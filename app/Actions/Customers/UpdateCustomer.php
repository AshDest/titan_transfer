<?php

namespace App\Actions\Customers;

use App\Enums\RecordStatus;
use App\Models\Customer;
use App\Models\User;
use App\Services\OperationNotifications;
use App\Validation\PersonRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class UpdateCustomer
{
    /** @param array<string, mixed> $input */
    public function handle(User $actor, Customer $customer, array $input): Customer
    {
        Gate::forUser($actor)->authorize('update', $customer);
        $data = Validator::make($input, [
            ...PersonRules::registration(),
            'email' => ['nullable', 'email', 'max:255'],
            'status' => ['required', Rule::enum(RecordStatus::class)],
        ], ['status.enum' => 'Sélectionnez un statut valide.'])->validate();
        $data = PersonRules::nameAttributes($data);

        return DB::transaction(function () use ($actor, $customer, $data): Customer {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $customer->fill($data)->save();
            app(OperationNotifications::class)->record($customer, $actor, 'updated');

            return $customer;
        });
    }
}
