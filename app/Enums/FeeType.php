<?php

namespace App\Enums;

enum FeeType: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Montant fixe',
            self::Percentage => 'Pourcentage',
        };
    }
}
