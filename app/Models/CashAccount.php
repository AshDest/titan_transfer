<?php

namespace App\Models;

use App\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashAccount extends Model
{
    use HasFactory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['balance' => 'decimal:2', 'opening_balance' => 'decimal:2', 'status' => RecordStatus::class];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->when(! $user->canViewAllCashAccounts(), fn (Builder $query) => $query->where('branch_id', $user->branch_id));
    }
}
