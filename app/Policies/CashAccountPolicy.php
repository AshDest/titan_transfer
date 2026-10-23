<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\RecordStatus;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\User;

class CashAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessApplication() && $user->can(Permission::CashView->value);
    }

    public function view(User $user, CashAccount $account): bool
    {
        return $this->viewAny($user) && ($user->canViewAllCashAccounts() || $user->branch_id === $account->branch_id);
    }

    public function createWithdrawal(User $user): bool
    {
        return $this->viewAny($user) && $user->can(Permission::CashWithdraw->value)
            && ($user->hasRole(Role::Administrator->value) || $user->isCashier());
    }

    public function createFunding(User $user): bool
    {
        return $this->viewAny($user) && $user->can(Permission::CashDeposit->value)
            && ! $user->hasRole(Role::SourceCashier->value)
            && $user->hasAnyRole([Role::Administrator->value, Role::DestinationCashier->value]);
    }

    public function fundBranch(User $user, Branch $branch): bool
    {
        return $this->createFunding($user) && $branch->status === RecordStatus::Active
            && ($user->canViewAllCashAccounts() || $user->branch_id === $branch->id);
    }

    public function viewFeeRevenue(User $user): bool
    {
        return $user->canAccessApplication() && ! $user->isCashier()
            && $user->hasAnyRole([Role::Administrator->value, Role::Manager->value])
            && $user->can(Permission::ReportsView->value);
    }

    public function withdrawFeeRevenue(User $user): bool
    {
        return $this->viewFeeRevenue($user) && $user->hasRole(Role::Administrator->value)
            && $this->createWithdrawal($user);
    }

    public function withdrawFees(User $user, CashAccount $account): bool
    {
        return $this->withdrawFeeRevenue($user) && $this->withdraw($user, $account);
    }

    public function withdraw(User $user, CashAccount $account): bool
    {
        return $this->createWithdrawal($user) && $this->view($user, $account)
            && $account->status === RecordStatus::Active && $account->branch->status === RecordStatus::Active;
    }
}
