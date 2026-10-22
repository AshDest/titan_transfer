<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::UsersManage->value);
    }

    public function view(User $user, User $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->canAccessApplication() && ! $user->isCashier()
            && $user->hasRole(Role::Administrator->value)
            && $user->can(Permission::UsersManage->value);
    }

    public function update(User $user, User $record): bool
    {
        return false;
    }

    public function updatePhone(User $user, User $record): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, User $record): bool
    {
        return false;
    }
}
