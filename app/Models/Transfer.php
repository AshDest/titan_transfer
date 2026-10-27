<?php

namespace App\Models;

use App\Enums\CashMovementType;
use App\Enums\FeeType;
use App\Enums\TransferStatus;
use App\Models\Concerns\PreservesFinancialHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Transfer extends Model
{
    use HasFactory, PreservesFinancialHistory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['fee_type' => FeeType::class, 'paid_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'parties_snapshot' => 'array', 'status' => TransferStatus::class,
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
                    ->whereIn('status', [TransferStatus::Pending, TransferStatus::Approved, TransferStatus::ReadyForPayout,
                        TransferStatus::Paid, TransferStatus::Rejected, TransferStatus::Cancelled, TransferStatus::Reversed]))));
    }

    public function sourceBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'source_branch_id');
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TransferEvent::class);
    }

    public function feeWithdrawalItems(): HasMany
    {
        return $this->hasMany(FeeWithdrawalItem::class);
    }

    public function depositMovement(): HasOne
    {
        return $this->hasOne(CashMovement::class)->where('type', CashMovementType::Deposit);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }
}
