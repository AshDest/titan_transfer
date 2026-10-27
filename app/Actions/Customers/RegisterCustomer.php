<?php

namespace App\Actions\Customers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Services\OperationNotifications;
use App\Validation\PersonRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class RegisterCustomer
{
    /** @param array<string, mixed> $input */
    public function handle(User $actor, Branch $branch, array $input): Customer
    {
        Gate::forUser($actor)->authorize('create', [Customer::class, $branch]);

        $data = Validator::make($input, [
            ...PersonRules::registration(),
            'email' => ['nullable', 'email', 'max:255'],
        ])->validate();
        $data = PersonRules::nameAttributes($data);

        return DB::transaction(function () use ($actor, $branch, $data): Customer {
            $customer = new Customer($data);
            $customer->branch()->associate($branch);
            $customer->createdBy()->associate($actor);
            $customer->save();

            app(OperationNotifications::class)->record($customer, $actor);

            return $customer->refresh();
        });
    }
}
