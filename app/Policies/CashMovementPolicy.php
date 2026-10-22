<?php

namespace App\Policies;

use App\Models\CashMovement;
use App\Models\User;

class CashMovementPolicy
{
    public function view(User $user, CashMovement $movement): bool
    {
        return $user->can('view', $movement->account);
    }

    public function update(User $user, CashMovement $movement): bool
    {
        return false;
    }

    public function delete(User $user, CashMovement $movement): bool
    {
        return false;
    }
}
