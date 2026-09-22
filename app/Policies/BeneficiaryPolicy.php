<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\RecordStatus;
use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\User;

class BeneficiaryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::BeneficiariesView->value);
    }

    public function view(User $user, Beneficiary $record): bool
    {
        return $this->viewAny($user) && $user->canAccessBranch($record->branch);
    }

    public function create(User $user, Branch $branch): bool
    {
        return $user->can(Permission::BeneficiariesCreate->value)
            && $user->canAccessBranch($branch)
            && $branch->status === RecordStatus::Active;
    }

    public function update(User $user, Beneficiary $record): bool
    {
        return false;
    }

    public function delete(User $user, Beneficiary $record): bool
    {
        return false;
    }
}
