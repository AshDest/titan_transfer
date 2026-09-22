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
}
