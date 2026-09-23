<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class DecimalFormatter
{
    public static function money(string $value): string
    {
        return self::localize((string) BigDecimal::of($value)->toScale(2, RoundingMode::HalfUp));
    }

    public static function rate(string $value): string
    {
        return self::compact($value, 4);
    }

    public static function percentage(string $value): string
    {
        return self::compact($value, 2);
    }

    private static function compact(string $value, int $scale): string
    {
        $original = BigDecimal::of($value);
        $rounded = $original->toScale($scale, RoundingMode::HalfUp);
        // Never render a positive exchange rate or fee as zero.
        $decimal = (string) ($rounded->isZero() && ! $original->isZero() ? $original : $rounded);
        if (str_contains($decimal, '.')) {
            $decimal = rtrim(rtrim($decimal, '0'), '.');
        }

        return self::localize($decimal);
    }

    private static function localize(string $value): string
    {
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, null);
        $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', "\u{202F}", $integer);

        return $integer.($fraction !== null ? ','.$fraction : '');
    }
}
