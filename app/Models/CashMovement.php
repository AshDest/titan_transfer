<?php

namespace App\Models;

use App\Enums\CashMovementType;
use App\Models\Concerns\PreservesFinancialHistory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashMovement extends Model
{
    use HasFactory, PreservesFinancialHistory;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['type' => CashMovementType::class, 'amount' => 'decimal:2', 'balance_before' => 'decimal:2', 'balance_after' => 'decimal:2'];
    }
}
