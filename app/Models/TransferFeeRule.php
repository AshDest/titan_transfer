<?php

namespace App\Models;

use App\Enums\FeeType;
use Database\Factories\TransferFeeRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['name', 'source_branch_id', 'destination_branch_id', 'source_currency', 'fee_type', 'fixed_amount', 'percentage', 'minimum_amount', 'maximum_amount', 'active', 'effective_from', 'effective_until'])]
class TransferFeeRule extends Model
{
    /** @use HasFactory<TransferFeeRuleFactory> */
    use HasFactory;

    /** Financial values are immutable; removal only hides the rule while retaining its history. */
    protected static function booted(): void
    {
        static::updating(function (self $rule): void {
            if ($rule->getOriginal('deleted_at') !== null || $rule->deleted_at === null
                || array_diff(array_keys($rule->getDirty()), ['deleted_at', 'updated_at']) !== []) {
                throw new LogicException('Financial configuration is immutable. Create a new version instead.');
            }
        });
        static::deleting(function (self $rule): void {
            throw new LogicException('Financial configuration history cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'fee_type' => FeeType::class,
            'fixed_amount' => 'decimal:2',
            'percentage' => 'decimal:4',
            'minimum_amount' => 'decimal:2',
            'maximum_amount' => 'decimal:2',
            'active' => 'boolean',
            'deleted_at' => 'immutable_datetime',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
        ];
    }

    public function sourceBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'source_branch_id');
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
