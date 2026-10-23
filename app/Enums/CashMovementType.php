<?php

namespace App\Enums;

enum CashMovementType: string
{
    case Deposit = 'DEPOSIT';
    case Payout = 'PAYOUT';
    case CashIn = 'CASH_IN';
    case CashOut = 'CASH_OUT';
    case Adjustment = 'ADJUSTMENT';
    case Reversal = 'REVERSAL';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Encaissement de transfert',
            self::Payout => 'Paiement de transfert',
            self::CashIn => 'Approvisionnement',
            self::CashOut => 'Sortie de caisse',
            self::Adjustment => 'Ajustement',
            self::Reversal => 'Extourne',
        };
    }
}
