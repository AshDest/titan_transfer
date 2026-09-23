<?php

namespace App\Actions\Beneficiaries;

use App\Enums\RecordStatus;
use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Services\OperationNotifications;
use App\Validation\PersonRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class RegisterBeneficiary
{
    /** @param array<string, mixed> $input */
    public function handle(User $actor, Branch $branch, array $input): Beneficiary
    {
        Gate::forUser($actor)->authorize('create', [Beneficiary::class, $branch]);

        $data = Validator::make($input, [
            ...PersonRules::registration(),
            'customer_id' => ['nullable', 'integer', Rule::exists(Customer::class, 'id')
                ->where('branch_id', $branch->id)->where('status', RecordStatus::Active->value)],
        ])->validate();

        return DB::transaction(function () use ($actor, $branch, $data): Beneficiary {
            $beneficiary = new Beneficiary(collect($data)->except('customer_id')->all());
            $beneficiary->customer_id = $data['customer_id'] ?? null;
            $beneficiary->branch()->associate($branch);
            $beneficiary->createdBy()->associate($actor);
            $beneficiary->save();

            app(OperationNotifications::class)->record($beneficiary, $actor);

            return $beneficiary->refresh();
        });
    }
}
