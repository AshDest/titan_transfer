<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::BranchesView->value);
    }

    public function view(User $user, Branch $record): bool
    {
        return $this->viewAny($user) && $user->canAccessBranch($record);
    }

    public function create(User $user): bool
    {
        return ! $user->isCashier() && $user->can(Permission::BranchesManage->value);
    }

    public function update(User $user, Branch $record): bool
    {
        return $user->canAccessApplication() && $user->hasRole(Role::Administrator->value)
            && $this->create($user) && $this->view($user, $record);
    }

    public function delete(User $user, Branch $record): bool
    {
        return $this->update($user, $record);
    }
}
