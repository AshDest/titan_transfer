<?php

namespace App\Models;

use App\Models\Concerns\PreservesFinancialHistory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeWithdrawalItem extends Model
{
    use HasFactory, PreservesFinancialHistory;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(FeeWithdrawal::class, 'fee_withdrawal_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }
}
