<?php

namespace App\Actions\Transfers;

use App\Enums\CashMovementType;
use App\Enums\RecordStatus;
use App\Enums\TransferStatus;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Transfer;
use App\Models\TransferEvent;
use App\Models\User;
use App\Services\OperationNotifications;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PayTransfer
{
    /** @param array<string, mixed> $input */
    public function handle(User $actor, Transfer $transfer, array $input, ?string $ip = null): Transfer
    {
        Gate::forUser($actor)->authorize('pay', $transfer);
        Validator::make($input, ['confirmed' => ['accepted']], [
            'confirmed.accepted' => 'Confirmez la vérification du bénéficiaire et la remise des espèces.',
        ])->validate();

        return DB::transaction(function () use ($actor, $transfer, $ip): Transfer {
            Branch::whereKey([$transfer->source_branch_id, $transfer->destination_branch_id])->orderBy('id')->lockForUpdate()->get();
            $transfer = Transfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('pay', $transfer);
            if ($transfer->status === TransferStatus::Paid && $transfer->movements()->where('type', CashMovementType::Payout)->exists()) {
                return $transfer;
            }
            if (! $transfer->status->canTransitionTo(TransferStatus::Paid)) {
                throw ValidationException::withMessages(['payment' => 'Seul un transfert validé et non encore payé peut être remis au bénéficiaire.']);
            }
            $account = CashAccount::where('branch_id', $transfer->destination_branch_id)
                ->where('currency', $transfer->destination_currency)->lockForUpdate()->first();
            $amount = BigDecimal::of($transfer->payout_amount);
            if (! $account || $account->status !== RecordStatus::Active) {
                throw ValidationException::withMessages(['payment' => 'Approvisionnez une caisse active dans la devise du retrait avant le paiement.']);
            }
            $before = BigDecimal::of($account->balance);
            if ($amount->isLessThanOrEqualTo('0') || $before->isLessThan($amount)) {
                throw ValidationException::withMessages(['payment' => 'Solde insuffisant. Approvisionnez la caisse avant de payer ce transfert.']);
            }
            $after = $before->minus($amount)->toScale(2);
            $account->forceFill(['balance' => (string) $after])->save();
            $movement = new CashMovement;
            $beneficiary = $transfer->parties_snapshot['beneficiary'];
            $movement->forceFill([
                'cash_account_id' => $account->id, 'transfer_id' => $transfer->id, 'type' => CashMovementType::Payout,
                'currency' => $transfer->destination_currency, 'amount' => $transfer->payout_amount,
                'balance_before' => (string) $before, 'balance_after' => (string) $after,
                'recipient_name' => trim($beneficiary['first_name'].' '.($beneficiary['last_name'] ?? '')),
                'description' => 'Paiement '.$transfer->reference, 'created_by' => $actor->id,
            ])->save();
            $previous = $transfer->status;
            $at = now('UTC');
            $changes = ['status' => TransferStatus::Paid->value, 'paid_by' => $actor->id, 'paid_at' => $at];
            $updated = Transfer::whereKey($transfer->id)->where('status', $previous)->update([...$changes, 'updated_at' => $at]);
            if ($updated !== 1) {
                throw ValidationException::withMessages(['payment' => 'Ce transfert a déjà été traité. Actualisez la page.']);
            }
            $event = new TransferEvent;
            $event->forceFill(['transfer_id' => $transfer->id, 'user_id' => $actor->id, 'action' => 'TRANSFER_PAID',
                'old_values' => ['status' => $previous->value],
                'new_values' => [...$changes, 'cash_movement_id' => $movement->id, 'amount' => $transfer->payout_amount, 'currency' => $transfer->destination_currency],
                'ip_address' => $ip])->save();
            $transfer->refresh();
            app(OperationNotifications::class)->record($transfer, $actor, 'paid');

            return $transfer;
        }, 3);
    }
}
