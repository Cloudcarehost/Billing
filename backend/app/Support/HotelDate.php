<?php

namespace App\Support;

use App\Models\Hotel;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class HotelDate
{
    public static function now(Hotel $hotel, ?CarbonInterface $at = null): CarbonImmutable
    {
        $timezone = $hotel->timezone ?: config('app.timezone');

        return $at === null
            ? CarbonImmutable::now($timezone)
            : CarbonImmutable::parse($at)->timezone($timezone);
    }

    public static function businessDay(Hotel $hotel, ?CarbonInterface $at = null): CarbonImmutable
    {
        $now = self::now($hotel, $at);
        [$hour, $minute] = self::cutoffParts($hotel);
        $cutoff = $now->setTime($hour, $minute, 0);

        return $now->lt($cutoff) ? $now->subDay()->startOfDay() : $now->startOfDay();
    }

    public static function businessDate(Hotel $hotel, ?CarbonInterface $at = null): string
    {
        return self::businessDay($hotel, $at)->toDateString();
    }

    public static function period(Hotel $hotel, ?CarbonInterface $at = null): string
    {
        return self::businessDay($hotel, $at)->format('Ym');
    }

    /** @return array{0: string, 1: string} */
    public static function rangeForPeriod(Hotel $hotel, string $period, ?CarbonInterface $at = null): array
    {
        $day = self::businessDay($hotel, $at);

        return match ($period) {
            'week' => [$day->startOfWeek()->toDateString(), $day->endOfWeek()->toDateString()],
            'month' => [$day->startOfMonth()->toDateString(), $day->endOfMonth()->toDateString()],
            'year' => [$day->startOfYear()->toDateString(), $day->endOfYear()->toDateString()],
            default => [$day->toDateString(), $day->toDateString()],
        };
    }

    /** @return array{0: int, 1: int} */
    private static function cutoffParts(Hotel $hotel): array
    {
        $raw = substr((string) ($hotel->business_day_starts_at ?: '05:00:00'), 0, 8);
        $parts = array_pad(explode(':', $raw), 2, '0');

        return [(int) $parts[0], (int) $parts[1]];
    }
}
