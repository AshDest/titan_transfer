<?php

namespace App\Enums;

enum Role: string
{
    case Administrator = 'administrator';
    case SourceCashier = 'source-cashier';
    case DestinationCashier = 'destination-cashier';
    case Manager = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrateur',
            self::SourceCashier => 'Caissier de dépôt',
            self::DestinationCashier => 'Caissier de paiement',
            self::Manager => 'Responsable d’agence',
        };
    }
}
