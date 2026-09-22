<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\RecordStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::CustomersView->value);
    }

    public function view(User $user, Customer $record): bool
    {
        return $this->viewAny($user) && $user->canAccessBranch($record->branch);
    }

    public function create(User $user, Branch $branch): bool
    {
        return $user->can(Permission::CustomersCreate->value)
            && $user->canAccessBranch($branch)
            && $branch->status === RecordStatus::Active;
    }

    public function update(User $user, Customer $record): bool
    {
        return false;
    }

    public function delete(User $user, Customer $record): bool
    {
        return false;
    }
}
