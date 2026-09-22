<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ExchangeRate;
use App\Models\User;

class ExchangeRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ExchangeRatesView->value);
    }

    public function view(User $user, ExchangeRate $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ExchangeRatesManage->value);
    }

    public function update(User $user, ExchangeRate $record): bool
    {
        return false;
    }

    public function delete(User $user, ExchangeRate $record): bool
    {
        return false;
    }
}
