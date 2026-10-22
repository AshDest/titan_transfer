<?php

namespace App\Models;

use App\Models\Concerns\PreservesFinancialHistory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeWithdrawal extends Model
{
    use HasFactory, PreservesFinancialHistory;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    public function movement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class, 'cash_movement_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FeeWithdrawalItem::class);
    }
}
