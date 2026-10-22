<?php

namespace App\Actions\Finance;

use App\Enums\FeeType;
use App\Enums\RecordStatus;
use App\Models\Branch;
use App\Models\TransferFeeRule;
use App\Models\User;
use App\Services\OperationNotifications;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class RecordTransferFeeRule
{
    /** @param array<string, mixed> $input */
    public function handle(User $actor, array $input): TransferFeeRule
    {
        Gate::forUser($actor)->authorize('create', TransferFeeRule::class);

        $input['fee_type'] ??= FeeType::Percentage->value;
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'source_branch_id' => ['nullable', 'integer', Rule::exists(Branch::class, 'id')->where('status', RecordStatus::Active->value)],
            'destination_branch_id' => ['nullable', 'integer', 'different:source_branch_id', Rule::exists(Branch::class, 'id')->where('status', RecordStatus::Active->value)],
            'source_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/', 'not_in:EAD'],
            'fee_type' => ['required', Rule::enum(FeeType::class)],
            'fixed_amount' => ['exclude_unless:fee_type,fixed', 'required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,2})?$/D'],
            'percentage' => ['exclude_unless:fee_type,percentage', 'required', 'string', 'regex:/^(0|[1-9][0-9]{0,2})(\.[0-9]{1,4})?$/', 'numeric', 'between:0,100'],
            'minimum_amount' => ['nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,2})?$/'],
            'maximum_amount' => ['nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,2})?$/'],
            'active' => ['sometimes', 'boolean'],
            'effective_from' => ['nullable', 'date_format:Y-m-d H:i:s', 'required_with:effective_until'],
            'effective_until' => ['nullable', 'date_format:Y-m-d H:i:s', 'after:effective_from'],
        ], ['source_currency.not_in' => 'Le code du dirham des Émirats est AED, et non EAD.'])->validate();

        if (isset($data['minimum_amount'], $data['maximum_amount'])
            && BigDecimal::of($data['maximum_amount'])->isLessThan($data['minimum_amount'])) {
            throw ValidationException::withMessages([
                'maximum_amount' => 'The maximum amount must be greater than or equal to the minimum amount.',
            ]);
        }

        if ($data['source_currency'] === 'USD' && ($data['active'] ?? true)) {
            if ($data['fee_type'] === FeeType::Fixed->value
                && (! isset($data['maximum_amount']) || BigDecimal::of($data['maximum_amount'])->isGreaterThan('1000.00'))) {
                throw ValidationException::withMessages(['maximum_amount' => 'Une tranche de frais fixes en USD doit se terminer au plus à 1 000,00 USD.']);
            }
            if ($data['fee_type'] === FeeType::Percentage->value
                && BigDecimal::of($data['minimum_amount'] ?? '0')->isLessThanOrEqualTo('1000.00')) {
                throw ValidationException::withMessages(['minimum_amount' => 'En USD, le pourcentage commence à partir de 1 000,01 USD.']);
            }
        }

        $data['percentage'] = $data['fee_type'] === FeeType::Fixed->value ? '0.0000' : $data['percentage'];
        $data['fixed_amount'] = $data['fee_type'] === FeeType::Fixed->value ? $data['fixed_amount'] : null;

        return DB::transaction(function () use ($actor, $data): TransferFeeRule {
            $rule = new TransferFeeRule($data);
            $rule->createdBy()->associate($actor);
            $rule->save();

            $rule->refresh();
            app(OperationNotifications::class)->record($rule, $actor);

            return $rule;
        });
    }
}
