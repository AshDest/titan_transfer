<?php

namespace App\Policies;

use App\Enums\Permission;
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
        return $user->can(Permission::BranchesManage->value);
    }

    public function update(User $user, Branch $record): bool
    {
        return false;
    }

    public function delete(User $user, Branch $record): bool
    {
        return false;
    }
}
