<?php

namespace cs\Core;

final class PlaneBuilder
{
    /** @var array<string,Point> */
    private array $voxels = [];

    /** @return list<Plane> */
    public function create(Point $a, Point $b, Point $c, ?Point $d = null, float $jaggedness = 1.0): array
    {
        if ($d === null) {
            return $this->fromTriangle($a, $b, $c);
        }

        return $this->fromQuad($a, $b, $c, $d, $jaggedness);
    }

    /** @return list<Plane> */
    public function fromTriangle(Point $a, Point $b, Point $c): array
    {
        $planes = [];
        foreach ($this->voxelizeTriangle($a, $b, $c) as $voxelPoint) {
            // new Box($voxelPoint, 1, 1, 1); todo check
            $planes[] = new Wall($voxelPoint, true, 1, 1);
            $planes[] = new Wall($voxelPoint, false, 1, 1);
            $planes[] = new Floor($voxelPoint, 1, 1);
        }
        return $planes;
    }

    /** @return list<Plane> */
    public function fromQuad(Point $a, Point $b, Point $c, Point $d, float $jaggedness = 1.0): array
    {
        $points = [$a, $b, $c, $d];
        $minX = min($a->x, $b->x, $c->x, $d->x);
        $maxX = max($a->x, $b->x, $c->x, $d->x);
        $minY = min($a->y, $b->y, $c->y, $d->y);
        $maxY = max($a->y, $b->y, $c->y, $d->y);
        $minZ = min($a->z, $b->z, $c->z, $d->z);
        $maxZ = max($a->z, $b->z, $c->z, $d->z);

        // Floor
        if ($minY === $maxY) {
            $sort = [];
            foreach ($points as $point) {
                $sort[0][$point->x][] = $point;
                $sort[1][$point->z][] = $point;
            }

            // single floor
            if (count($sort[0]) === 2 && count($sort[1]) === 2 && count($sort[0][$minX]) === 2) {
                return [new Floor(new Point($minX, $minY, $minZ), $maxX - $minX, $maxZ - $minZ)];
            }

            GameException::notImplementedYet("skew floor?"); // @codeCoverageIgnore
        }

        // Maybe rotated wall or stairs
        if ($minX !== $maxX && $minZ !== $maxZ) {
            $wallRotated = [];
            $isWallRotatedCheck = [];
            $isStairs = [];
            foreach ($points as $point) {
                $index = ($point->y === $minY || $point->y === $maxY ? 0 : 1);
                $wallRotated[$point->x][] = $point;
                $isWallRotatedCheck[$index]["{$point->x}|{$point->z}"][] = $point;
                $isStairs[$point->x][$point->z][] = $point;
            }

            // It is wall rotated
            if (count($isWallRotatedCheck) === 1 && count($isWallRotatedCheck[0] ?? []) === 2) {
                $keys = array_keys($wallRotated);
                sort($keys, SORT_NUMERIC);
                $start = $wallRotated[$keys[0]][0] ?? GameException::invalid();
                $start->setY($minY);
                $end = $wallRotated[$keys[1]][0] ?? GameException::invalid();
                $end->setY($minY);

                return $this->rotatedWall($start, $end, $maxY - $minY, $jaggedness);
            }

            // Ramp
            if ($minY !== $maxY && count($isStairs[$minX][$minZ]) === 1) {
                $min = $isStairs[$minX][$minZ][0];
                $max = $isStairs[$minX][$maxZ][0];

                if ($min->y === $max->y) { // X direction
                    $max->y = ($min->y === $minY ? $maxY : $minY);
                    return $this->ramp($min, $max->setX($maxX)->setZ($minZ), $maxZ - $minZ, $jaggedness);
                }

                // Z direction
                $max->y = ($min->y === $minY ? $maxY : $minY);
                return $this->ramp($min, $max, $maxX - $minX, $jaggedness);
            }
        }

        // Wall
        if ($minX === $maxX || $minZ === $maxZ) {
            $widthOnXAxis = ($minZ === $maxZ);
            $wallWidth = ($widthOnXAxis ? $maxX - $minX : $maxZ - $minZ);

            return [new Wall(new Point($minX, $minY, $minZ), $widthOnXAxis, $wallWidth, $maxY - $minY)];
        }

        GameException::notImplementedYet(); // @codeCoverageIgnore
    }

    /** @return list<Wall> */
    private function rotatedWall(Point $start, Point $end, int $height, float $jaggedness = 1.0): array
    {
        [$angleH, $_angleV] = Util::worldAngle($end, $start);
        $angleH = $angleH ?? GameException::invalid();
        $direction = [Util::directionX($angleH), Util::directionZ($angleH)];
        assert(abs($direction[0]) === 1);
        assert(abs($direction[1]) === 1);

        $walls = [];
        $previous = $start->clone();
        $points = Util::continuousPointsBetween($start, $end, $jaggedness);
        $widthOnXAxis = ($points[1][2] === $points[0][2]);

        $i = 0;
        $maxIteration = count($points);
        while (++$i <= $maxIteration) {
            $xyz = $points[$i] ?? null;

            if ($xyz !== null) {
                $hasSameBaseAxisAsPrevious = (!$widthOnXAxis && $previous->x === $xyz[0]) || ($widthOnXAxis && $previous->z === $xyz[2]);
                if ($hasSameBaseAxisAsPrevious) {
                    continue;
                }
            }

            $current = $points[$i - 1];
            $width = abs($widthOnXAxis ? $previous->x - $current[0] : $previous->z - $current[2]);
            if ($direction[1] === 1) {
                $leftPoint = ($direction[0] === -1 && $widthOnXAxis ? new Point($current[0], $start->y, $current[2]) : $previous->clone());
            } else {
                $leftPoint = ($direction[0] === 1 && $widthOnXAxis ? $previous->clone() : new Point($current[0], $start->y, $current[2]));
            }

            $walls[] = new Wall($leftPoint, $widthOnXAxis, $width, $height);
            $widthOnXAxis = !$widthOnXAxis;
            $previous->set($current[0], $start->y, $current[2]);
        }

        return $walls;
    }

    /** @return list<Plane> */
    private function ramp(Point $start, Point $end, int $width, float $jaggedness = 1.0): array
    {
        [$angleH, $angleV] = Util::worldAngle($end, $start);
        if ($angleH === null || fmod($angleH, 90) !== 0.0) {
            GameException::invalid(); // @codeCoverageIgnore
        }
        assert($angleV !== 0.0);

        $planes = [];
        $previous = $start->clone();
        $points = Util::continuousPointsBetween($start, $end, $jaggedness);
        $isFloor = ($points[1][1] === $points[0][1]);
        $wallWidthOnXAxis = (abs(Util::directionX($angleH)) === 0);
        $stairsGoingUp = ($angleV > 0);

        $i = 0;
        $maxIteration = count($points);
        while (++$i <= $maxIteration) {
            $xyz = $points[$i] ?? null;

            if ($xyz !== null) {
                if ($isFloor) {
                    if ($previous->y === $xyz[1]) {
                        continue;
                    }
                } else {
                    $hasSameBaseAxisAsPrevious = (!$wallWidthOnXAxis && $previous->x === $xyz[0]) || ($wallWidthOnXAxis && $previous->z === $xyz[2]);
                    if ($hasSameBaseAxisAsPrevious) {
                        continue;
                    }
                }
            }

            $current = $points[$i - 1];
            if ($isFloor) {
                if ($wallWidthOnXAxis) {
                    $planes[] = new Floor($previous->clone(), $width, $current[2] - $previous->z);
                } else {
                    $planes[] = new Floor($previous->clone(), $current[0] - $previous->x, $width);
                }
            } else {
                $wallStart = ($stairsGoingUp ? $previous->clone() : new Point(...$current));
                $planes[] = new Wall($wallStart, $wallWidthOnXAxis, $width, abs($current[1] - $previous->y));
            }
            $isFloor = !$isFloor;
            $previous->set($current[0], $current[1], $current[2]);
        }

        return $planes;
    }

    /** @return array<string,Point> */
    private function voxelizeTriangle(Point $a, Point $b, Point $c): array
    {
        $this->voxels = [];
        $this->voxelizeLine($a, $b);
        $this->voxelizeLine($b, $c);
        $this->voxelizeLine($c, $a);

        $perAxis = [[], [], []];
        foreach ($this->voxels as $voxel) {
            $perAxis[0][$voxel->x][$voxel->z] = $voxel;
            $perAxis[1][$voxel->y][$voxel->x] = $voxel;
            $perAxis[2][$voxel->z][$voxel->x] = $voxel;
        }
        foreach ($perAxis as $axisData) {
            foreach ($axisData as $data) {
                if (count($data) === 1) {
                    continue;
                }
                $axisKeys = array_keys($data);
                $this->voxelizeLine($data[min($axisKeys)], $data[max($axisKeys)]);
            }
        }

        return $this->voxels;
    }

    private function voxelizeLine(Point $start, Point $end): void
    {
        $x = $start->x;
        $y = $start->y;
        $z = $start->z;

        [$steps, $xIncrement, $yIncrement, $zIncrement] = Util::stepsAndIncrements($start, $end);
        for ($i = 0; $i <= $steps; $i++) {
            $this->addVoxel((int)round($x), (int)round($y), (int)round($z));
            $x += $xIncrement;
            $y += $yIncrement;
            $z += $zIncrement;
        }
    }

    private function addVoxel(int $x, int $y, int $z): void
    {
        $key = "{$x},{$y},{$z}";
        if (isset($this->voxels[$key])) {
            return;
        }

        $this->voxels[$key] = new Point($x, $y, $z);
    }

}
