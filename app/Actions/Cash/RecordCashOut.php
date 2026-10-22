<?php

namespace App\Actions\Cash;

use App\Enums\CashMovementType;
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

final class RecordCashOut
{
    /** @param array<string, mixed> $input */
    public function handle(User $actor, CashAccount $account, array $input): CashMovement
    {
        Gate::forUser($actor)->authorize('withdraw', $account);
        foreach (['recipient_name', 'description'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim($input[$field]);
            }
        }
        $data = Validator::make($input, [
            'amount' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,2})?$/D', 'numeric', 'gt:0'],
            'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now($account->branch->timezone)->toDateString()],
            'recipient_name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'submission_key' => ['required', 'uuid'],
            'confirmed' => ['accepted'],
        ], [
            'required' => 'Ce champ est obligatoire.',
            'amount.regex' => 'Saisissez un montant avec au maximum deux décimales, séparées par un point.',
            'amount.gt' => 'Le montant doit être supérieur à zéro.',
            'occurred_on.date_format' => 'Saisissez une date valide.',
            'occurred_on.before_or_equal' => 'La date ne peut pas être dans le futur pour cette agence.',
            'recipient_name.max' => 'Le nom du bénéficiaire est trop long (255 caractères maximum).',
            'description.max' => 'Le motif est trop long (255 caractères maximum).',
            'confirmed.accepted' => 'Confirmez que vous avez remis les espèces au bénéficiaire.',
            'submission_key.uuid' => 'Rechargez le formulaire avant de recommencer.',
        ])->validate();
        $amount = BigDecimal::of($data['amount'])->toScale(2);

        return DB::transaction(function () use ($actor, $account, $data, $amount): CashMovement {
            $branch = Branch::whereKey($account->branch_id)->lockForUpdate()->firstOrFail();
            $account = CashAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $account->setRelation('branch', $branch);
            Gate::forUser($actor)->authorize('withdraw', $account);
            $existing = CashMovement::where('submission_key', $data['submission_key'])->first();
            if ($existing) {
                if ($existing->created_by !== $actor->id || $existing->cash_account_id !== $account->id
                    || $existing->amount !== (string) $amount || $existing->recipient_name !== $data['recipient_name']
                    || $existing->description !== $data['description'] || $existing->occurred_on->toDateString() !== $data['occurred_on']) {
                    throw ValidationException::withMessages(['submission_key' => 'Ce formulaire a déjà été utilisé. Ouvrez une nouvelle sortie de caisse.']);
                }

                return $existing;
            }
            $before = BigDecimal::of($account->balance);
            if ($before->isLessThan($amount)) {
                throw ValidationException::withMessages(['amount' => 'Solde insuffisant pour cette sortie de caisse.']);
            }
            $after = $before->minus($amount)->toScale(2);
            $account->forceFill(['balance' => (string) $after])->save();
            $movement = new CashMovement;
            $movement->forceFill([
                'cash_account_id' => $account->id, 'transfer_id' => null, 'type' => CashMovementType::CashOut,
                'currency' => $account->currency, 'amount' => (string) $amount, 'balance_before' => (string) $before,
                'balance_after' => (string) $after, 'occurred_on' => $data['occurred_on'],
                'recipient_name' => $data['recipient_name'], 'description' => $data['description'],
                'created_by' => $actor->id, 'submission_key' => $data['submission_key'],
            ])->save();
            app(OperationNotifications::class)->record($movement, $actor);

            return $movement;
        }, 3);
    }
}
