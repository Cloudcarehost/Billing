<?php

namespace App\Support;

/**
 * Money is stored and compared in integer minor units (paise).
 * Rounding policy: multiply then round half-up to the nearest paise.
 * Line amounts multiply unit paise by quantity, then round to paise.
 */
class Money
{
    public const SCALE = 100;

    public static function toMinor(int|float|string $amount): int
    {
        return (int) round(((float) $amount) * self::SCALE, 0, PHP_ROUND_HALF_UP);
    }

    public static function fromMinor(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return $sign.sprintf('%d.%02d', intdiv($minor, self::SCALE), $minor % self::SCALE);
    }

    public static function add(int|float|string ...$amounts): string
    {
        return self::fromMinor(array_sum(array_map(fn (int|float|string $amount) => self::toMinor($amount), $amounts)));
    }

    public static function subtract(int|float|string $left, int|float|string $right): string
    {
        return self::fromMinor(self::toMinor($left) - self::toMinor($right));
    }

    /** @return array{subtotal: string, tax: string, total: string} */
    public static function taxedLine(int|float|string $unitPrice, int|float|string $quantity, int|float|string $taxRate, bool $priceIncludesTax): array
    {
        $grossMinor = (int) round(self::toMinor($unitPrice) * (float) $quantity, 0, PHP_ROUND_HALF_UP);
        $rate = (float) $taxRate;
        if ($priceIncludesTax) {
            $subtotalMinor = $rate > 0 ? (int) round($grossMinor / (1 + $rate / 100), 0, PHP_ROUND_HALF_UP) : $grossMinor;
            $taxMinor = $grossMinor - $subtotalMinor;

            return ['subtotal' => self::fromMinor($subtotalMinor), 'tax' => self::fromMinor($taxMinor), 'total' => self::fromMinor($grossMinor)];
        }
        $taxMinor = (int) round($grossMinor * $rate / 100, 0, PHP_ROUND_HALF_UP);

        return ['subtotal' => self::fromMinor($grossMinor), 'tax' => self::fromMinor($taxMinor), 'total' => self::fromMinor($grossMinor + $taxMinor)];
    }

    /** @return array{0: string, 1: string} */
    public static function splitHalves(int|float|string $amount): array
    {
        $minor = self::toMinor($amount);
        $first = intdiv($minor, 2);

        return [self::fromMinor($first), self::fromMinor($minor - $first)];
    }
}
