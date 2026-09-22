<?php

namespace App\Models;

use App\Enums\TransferStatus;
use App\Models\Concerns\PreservesFinancialHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transfer extends Model
{
    use HasFactory, PreservesFinancialHistory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['parties_snapshot' => 'array', 'status' => TransferStatus::class,
            'amount_received' => 'decimal:2', 'fee_percentage' => 'decimal:4', 'fee_amount' => 'decimal:2',
            'net_amount' => 'decimal:2', 'exchange_rate' => 'decimal:6', 'payout_amount' => 'decimal:2'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->when(! $user->can('branches.view-all'), fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('source_branch_id', $user->branch_id)
                ->orWhere(fn (Builder $query) => $query->where('destination_branch_id', $user->branch_id)
                    ->whereIn('status', [TransferStatus::Approved, TransferStatus::ReadyForPayout, TransferStatus::Paid]))));
    }

    public function sourceBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'source_branch_id');
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TransferEvent::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }
}
