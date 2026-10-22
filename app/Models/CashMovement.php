<?php

namespace App\Models;

use App\Enums\CashMovementType;
use App\Models\Concerns\PreservesFinancialHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    use HasFactory, PreservesFinancialHistory;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['type' => CashMovementType::class, 'amount' => 'decimal:2', 'balance_before' => 'decimal:2', 'balance_after' => 'decimal:2', 'occurred_on' => 'immutable_date'];
    }

    protected function occurredOn(): Attribute
    {
        return Attribute::make(set: fn (mixed $value): ?string => $value === null ? null : CarbonImmutable::parse($value)->toDateString());
    }

    protected static function booted(): void
    {
        static::creating(function (self $movement): void {
            if ($movement->occurred_on === null) {
                $movement->occurred_on = CarbonImmutable::parse($movement->created_at ?? now('UTC'))
                    ->setTimezone($movement->account->branch->timezone)->toDateString();
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('account', fn (Builder $account) => $account->visibleTo($user));
    }
}
