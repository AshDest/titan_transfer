<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\TransferFeeRule;
use App\Models\User;

class TransferFeeRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::FeeRulesView->value);
    }

    public function view(User $user, TransferFeeRule $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return ! $user->isCashier() && $user->can(Permission::FeeRulesManage->value);
    }

    public function update(User $user, TransferFeeRule $record): bool
    {
        return false;
    }

    public function delete(User $user, TransferFeeRule $record): bool
    {
        return $user->canAccessApplication() && $user->hasRole(Role::Administrator->value) && $this->create($user);
    }
}
