<?php

namespace App\Policies;

use App\Enums\RecordStatus;
use App\Models\Branch;
use App\Models\Transfer;
use App\Models\User;

class TransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('transfers.view');
    }

    public function view(User $user, Transfer $transfer): bool
    {
        return $this->viewAny($user) && Transfer::visibleTo($user)->whereKey($transfer->id)->exists();
    }

    public function create(User $user, Branch $branch): bool
    {
        return $user->canAccessApplication() && $user->can('transfers.create')
            && $user->canAccessBranch($branch) && $branch->status === RecordStatus::Active;
    }
}
