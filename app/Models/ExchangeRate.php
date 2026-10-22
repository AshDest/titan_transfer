<?php

namespace App\Models;

use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['from_currency', 'to_currency', 'rate', 'effective_from', 'effective_until', 'active'])]
class ExchangeRate extends Model
{
    /** @use HasFactory<ExchangeRateFactory> */
    use HasFactory;

    /** Rates and validity dates remain immutable; only their activation state may change. */
    protected static function booted(): void
    {
        static::updating(function (self $rate): void {
            if (array_diff(array_keys($rate->getDirty()), ['active', 'updated_at']) !== []) {
                throw new LogicException('Financial configuration is immutable. Create a new version instead.');
            }
        });
        static::deleting(function (self $rate): void {
            if ($rate->active || Transfer::where('exchange_rate_id', $rate->id)->exists()) {
                throw new LogicException('Active or referenced exchange rates cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6',
            'active' => 'boolean',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
