<?php

namespace App\Actions\Cash;

use App\Enums\CashMovementType;
use App\Enums\RecordStatus;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\User;
use App\Services\OperationNotifications;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RecordCashIn
{
    /** @param array<string, mixed> $input */
    public function handle(User $actor, Branch $branch, array $input): CashMovement
    {
        Gate::forUser($actor)->authorize('fundBranch', [CashAccount::class, $branch]);
        foreach (['provider_name', 'description'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim($input[$field]);
            }
        }
        $data = Validator::make($input, [
            'amount' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,2})?$/D', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'in:'.$branch->default_currency],
            'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now($branch->timezone)->toDateString()],
            'provider_name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'submission_key' => ['required', 'uuid'],
            'confirmed' => ['accepted'],
        ], [
            'required' => 'Ce champ est obligatoire.',
            'amount.regex' => 'Saisissez un montant avec au maximum deux décimales, séparées par un point.',
            'amount.gt' => 'Le montant doit être supérieur à zéro.',
            'currency.in' => 'La devise doit correspondre à celle de l’agence.',
            'occurred_on.before_or_equal' => 'La date ne peut pas être dans le futur pour cette agence.',
            'confirmed.accepted' => 'Confirmez avoir reçu les espèces.',
            'submission_key.uuid' => 'Rechargez le formulaire avant de recommencer.',
        ])->validate();
        $amount = BigDecimal::of($data['amount'])->toScale(2);

        return DB::transaction(function () use ($actor, $branch, $data, $amount): CashMovement {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('fundBranch', [CashAccount::class, $branch]);
            if ($branch->default_currency !== $data['currency']) {
                throw ValidationException::withMessages(['currency' => 'La devise de l’agence a changé. Rechargez le formulaire.']);
            }
            $account = CashAccount::where('branch_id', $branch->id)->where('currency', $data['currency'])->lockForUpdate()->first();
            if ($account && $account->status !== RecordStatus::Active) {
                throw ValidationException::withMessages(['branch_id' => 'La caisse de cette agence est inactive.']);
            }
            $existing = CashMovement::where('submission_key', $data['submission_key'])->first();
            if ($existing) {
                if ($existing->type !== CashMovementType::CashIn || $existing->created_by !== $actor->id
                    || $existing->cash_account_id !== $account?->id || $existing->amount !== (string) $amount
                    || $existing->provider_name !== $data['provider_name'] || $existing->description !== $data['description']
                    || $existing->occurred_on->toDateString() !== $data['occurred_on']) {
                    throw ValidationException::withMessages(['submission_key' => 'Ce formulaire a déjà été utilisé. Ouvrez un nouvel approvisionnement.']);
                }

                return $existing;
            }
            if (! $account) {
                $account = new CashAccount;
                $account->forceFill(['branch_id' => $branch->id, 'name' => 'Caisse '.$branch->code,
                    'currency' => $data['currency'], 'opening_balance' => '0.00', 'balance' => '0.00', 'status' => RecordStatus::Active])->save();
            }
            $before = BigDecimal::of($account->balance);
            $after = $before->plus($amount)->toScale(2);
            if ($after->isGreaterThan('9999999999999999.99')) {
                throw ValidationException::withMessages(['amount' => 'Le solde maximal de la caisse serait dépassé.']);
            }
            $account->forceFill(['balance' => (string) $after])->save();
            $movement = new CashMovement;
            $movement->forceFill([
                'cash_account_id' => $account->id, 'transfer_id' => null, 'type' => CashMovementType::CashIn,
                'currency' => $account->currency, 'amount' => (string) $amount, 'balance_before' => (string) $before,
                'balance_after' => (string) $after, 'occurred_on' => $data['occurred_on'],
                'provider_name' => $data['provider_name'], 'description' => $data['description'],
                'created_by' => $actor->id, 'submission_key' => $data['submission_key'],
            ])->save();
            app(OperationNotifications::class)->record($movement, $actor);

            return $movement;
        }, 3);
    }
}
