<?php

namespace App\Policies;

use App\Enums\RecordStatus;
use App\Enums\Role;
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

    public function reviewAny(User $user): bool
    {
        return $user->canAccessApplication() && ! $user->isCashier()
            && $user->hasAnyRole([Role::Administrator->value, Role::Manager->value])
            && $user->canAny(['transfers.approve', 'transfers.reject']) && $this->viewAny($user);
    }

    public function approve(User $user, Transfer $transfer): bool
    {
        return $this->canReview($user, $transfer) && $user->can('transfers.approve');
    }

    public function reject(User $user, Transfer $transfer): bool
    {
        return $this->canReview($user, $transfer) && $user->can('transfers.reject');
    }

    public function payAny(User $user): bool
    {
        return $user->canAccessApplication() && $user->can('transfers.pay') && $this->viewAny($user)
            && ! $user->hasRole(Role::SourceCashier->value)
            && $user->hasAnyRole([Role::Administrator->value, Role::DestinationCashier->value]);
    }

    public function pay(User $user, Transfer $transfer): bool
    {
        return $this->payAny($user) && $this->view($user, $transfer)
            && ($user->canViewAllCashAccounts() || $user->branch_id === $transfer->destination_branch_id)
            && $transfer->destinationBranch->status === RecordStatus::Active;
    }

    private function canReview(User $user, Transfer $transfer): bool
    {
        return $user->canAccessApplication() && ! $user->isCashier()
            && $user->hasAnyRole([Role::Administrator->value, Role::Manager->value])
            && $this->view($user, $transfer)
            && ($user->hasRole(Role::Administrator->value) || $user->branch_id === $transfer->source_branch_id)
            && $transfer->sourceBranch->status === RecordStatus::Active;
    }
}
