<?php

namespace App\Support;

/** Inventory quantities use milli-units (3 decimal places) with half-up rounding. */
class Quantity
{
    public const SCALE = 1000;

    public static function toMilli(int|float|string $quantity): int
    {
        return (int) round(((float) $quantity) * self::SCALE, 0, PHP_ROUND_HALF_UP);
    }

    public static function fromMilli(int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $milli = abs($milli);

        return $sign.sprintf('%d.%03d', intdiv($milli, self::SCALE), $milli % self::SCALE);
    }

    public static function add(int|float|string $left, int|float|string $right): string
    {
        return self::fromMilli(self::toMilli($left) + self::toMilli($right));
    }
}
