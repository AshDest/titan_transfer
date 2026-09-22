<?php

namespace App\Enums;

enum Role: string
{
    case Administrator = 'administrator';
    case SourceCashier = 'source-cashier';
    case DestinationCashier = 'destination-cashier';
    case Manager = 'manager';
}
