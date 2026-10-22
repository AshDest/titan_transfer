<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DeleteCustomer
{
    public function handle(User $actor, Customer $customer): void
    {
        Gate::forUser($actor)->authorize('delete', $customer);
        $message = 'Suppression impossible : ce client possède des bénéficiaires ou des transferts. Vous pouvez le désactiver pour conserver son historique.';
        try {
            DB::transaction(function () use ($customer, $message): void {
                $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
                if ($customer->hasDependencies()) {
                    throw ValidationException::withMessages(['deletion' => $message]);
                }
                $customer->delete();
            });
        } catch (QueryException $exception) {
            if (in_array($exception->getCode(), ['23000', '23503'], true)) {
                throw ValidationException::withMessages(['deletion' => $message]);
            }
            throw $exception;
        }
    }
}
