<?php

namespace App\Models;

use App\Enums\FeeType;
use App\Models\Concerns\PreservesFinancialHistory;
use Database\Factories\TransferFeeRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'source_branch_id', 'destination_branch_id', 'source_currency', 'fee_type', 'fixed_amount', 'percentage', 'minimum_amount', 'maximum_amount', 'active', 'effective_from', 'effective_until'])]
class TransferFeeRule extends Model
{
    /** @use HasFactory<TransferFeeRuleFactory> */
    use HasFactory, PreservesFinancialHistory;

    protected function casts(): array
    {
        return [
            'fee_type' => FeeType::class,
            'fixed_amount' => 'decimal:2',
            'percentage' => 'decimal:4',
            'minimum_amount' => 'decimal:2',
            'maximum_amount' => 'decimal:2',
            'active' => 'boolean',
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
