<?php

namespace App\Support;

/**
 * Number formatting for stats that works without PHP's intl extension
 * (Laravel's Number helper requires intl, which XAMPP disables by default).
 */
class Metric
{
    /** 1234 → "1.2K", 2500000 → "2.5M". */
    public static function short(int|float|null $value): string
    {
        $value = (float) $value;

        foreach ([1_000_000_000 => 'B', 1_000_000 => 'M', 1_000 => 'K'] as $size => $suffix) {
            if (abs($value) >= $size) {
                return rtrim(rtrim(number_format($value / $size, 1), '0'), '.').$suffix;
            }
        }

        return number_format($value);
    }

    /** 1234567 → "1,234,567". */
    public static function full(int|float|null $value): string
    {
        return number_format((float) $value);
    }
}
