<?php

namespace App\Validation;

use App\Enums\RecordStatus;
use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class TransferInput
{
    /** @return array{data: array, source: Branch, destination: Branch, sender: ?Customer, beneficiary: ?Beneficiary} */
    public function prepare(User $actor, array $input): array
    {
        Gate::forUser($actor)->authorize('transfers.create');
        if (isset($input['amount_received']) && is_string($input['amount_received'])) {
            $input['amount_received'] = str_replace(',', '.', trim($input['amount_received']));
        }
        $input['fee_mode'] ??= 'configured';
        if (is_string($input['custom_fee_percentage'] ?? null)) {
            $input['custom_fee_percentage'] = str_replace(',', '.', trim($input['custom_fee_percentage']));
        }
        $rules = [
            'source_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'destination_branch_id' => ['required', 'integer', 'different:source_branch_id', 'exists:branches,id'],
            'amount_received' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/D', 'gt:0'],
            'fee_mode' => ['required', 'in:configured,custom_percentage'],
            'custom_fee_percentage' => ['exclude_unless:fee_mode,custom_percentage', 'required', 'string', 'regex:/^(0|[1-9][0-9]{0,2})(\.[0-9]{1,4})?$/D', 'numeric', 'gte:0', 'lt:100'],
            'sender_mode' => ['required', 'in:existing,new'],
            'beneficiary_mode' => ['required', 'in:existing,new'],
            'sender_id' => ['exclude_unless:sender_mode,existing', 'required', 'integer'],
            'beneficiary_id' => ['exclude_unless:beneficiary_mode,existing', 'required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
        foreach (['sender', 'beneficiary'] as $party) {
            $rules[$party] = ['exclude_unless:'.$party.'_mode,new', 'required', 'array:full_name,first_name,last_name,phone,country,city,address,id_type,id_number'];
            foreach (PersonRules::registration() as $field => $fieldRules) {
                if ($field === 'country') {
                    $rules[$party.'.'.$field] = ['exclude'];

                    continue;
                }
                $rules[$party.'.'.$field] = ['exclude_unless:'.$party.'_mode,new', ...array_map(
                    fn (string $rule): string => str_replace(['required_with:', 'exclude_with:'], ['required_with:'.$party.'.', 'exclude_with:'.$party.'.'], $rule), $fieldRules)];
            }
        }
        $data = Validator::make($input, $rules, [
            'custom_fee_percentage.required' => 'Saisissez le pourcentage personnalisé, ou 0 pour un transfert sans frais.',
            'custom_fee_percentage.regex' => 'Saisissez un pourcentage positif ou nul, avec au maximum quatre décimales.',
            'custom_fee_percentage.lt' => 'Le pourcentage doit être inférieur à 100 % pour laisser un montant au bénéficiaire.',
        ])->validate();
        foreach (['sender', 'beneficiary'] as $party) {
            if ($data[$party.'_mode'] === 'new') {
                $data[$party]['last_name'] ??= null;
            }
        }
        $source = Branch::findOrFail($data['source_branch_id']);
        $destination = Branch::findOrFail($data['destination_branch_id']);
        Gate::forUser($actor)->authorize('create', [Transfer::class, $source]);
        if ($destination->status !== RecordStatus::Active) {
            throw ValidationException::withMessages(['destination_branch_id' => 'Cette agence est inactive.']);
        }
        $sender = null;
        if ($data['sender_mode'] === 'new') {
            Gate::forUser($actor)->authorize('create', [Customer::class, $source]);
            $data['sender']['country'] = $source->country;
        } else {
            $sender = Customer::where('branch_id', $source->id)->where('status', RecordStatus::Active)->find($data['sender_id']);
            if (! $sender) {
                throw ValidationException::withMessages(['sender_id' => 'Sélectionnez un client actif de votre agence.']);
            }
        }
        $beneficiary = null;
        if ($data['beneficiary_mode'] === 'new') {
            Gate::forUser($actor)->authorize('create', [Beneficiary::class, $source]);
            $data['beneficiary']['country'] = $destination->country;
        } else {
            $beneficiary = Beneficiary::where('branch_id', $source->id)->where('status', RecordStatus::Active)
                ->where(fn ($query) => $query->whereNull('customer_id')->when($sender, fn ($q) => $q->orWhere('customer_id', $sender->id)))
                ->find($data['beneficiary_id']);
            if (! $beneficiary) {
                throw ValidationException::withMessages(['beneficiary_id' => 'Sélectionnez un bénéficiaire actif associé à ce client ou sans client associé.']);
            }
        }

        return compact('data', 'source', 'destination', 'sender', 'beneficiary');
    }
}
