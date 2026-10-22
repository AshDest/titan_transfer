<?php

namespace App\Services;

final class PhoneNumber
{
    public const string PATTERN = '/^\+[1-9][0-9]{7,14}$/D';

    public static function normalize(string $phone): string
    {
        $phone = str_replace([' ', '-', '(', ')', '.', "\u{00A0}", "\u{202F}"], '', trim($phone));

        return str_starts_with($phone, '00') ? '+'.substr($phone, 2) : $phone;
    }
}
