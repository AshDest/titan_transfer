<?php

namespace App\Models;

use App\Enums\RecordStatus;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'country', 'city', 'default_currency', 'timezone', 'status'])]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function cashAccounts(): HasMany
    {
        return $this->hasMany(CashAccount::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(Beneficiary::class);
    }

    public function hasFinancialRecords(): bool
    {
        return CashAccount::where('branch_id', $this->id)->exists()
            || Transfer::where('source_branch_id', $this->id)->orWhere('destination_branch_id', $this->id)->exists()
            || TransferFeeRule::where('source_branch_id', $this->id)->orWhere('destination_branch_id', $this->id)->exists();
    }

    public function hasDependencies(): bool
    {
        return $this->users()->exists() || $this->customers()->exists()
            || $this->beneficiaries()->exists() || $this->hasFinancialRecords();
    }
}
