<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\RecordStatus;
use App\Enums\Role;
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

    public function withdraw(User $user, CashAccount $account): bool
    {
        return $this->createWithdrawal($user) && $this->view($user, $account)
            && $account->status === RecordStatus::Active && $account->branch->status === RecordStatus::Active;
    }
}
