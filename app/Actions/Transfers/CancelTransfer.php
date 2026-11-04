<?php

namespace App\Actions\Transfers;

use App\Enums\CashMovementType;
use App\Enums\TransferStatus;
use App\Models\Branch;
use App\Models\Transfer;
use App\Models\TransferEvent;
use App\Models\User;
use App\Services\OperationNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CancelTransfer
{
    /** @param array<string, mixed> $input */
    public function handle(User $actor, Transfer $transfer, array $input, ?string $ip = null): Transfer
    {
        Gate::forUser($actor)->authorize('cancel', $transfer);
        if (is_string($input['cancellation_reason'] ?? null)) {
            $input['cancellation_reason'] = trim($input['cancellation_reason']);
        }
        $data = Validator::make($input, [
            'confirmed' => ['accepted'],
            'cancellation_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'confirmed.accepted' => 'Confirmez l’annulation du transfert.',
            'cancellation_reason.required' => 'Indiquez le motif de l’annulation.',
            'cancellation_reason.min' => 'Le motif doit contenir au moins 5 caractères.',
        ])->validate();

        return DB::transaction(function () use ($actor, $transfer, $data, $ip): Transfer {
            Branch::whereKey([$transfer->source_branch_id, $transfer->destination_branch_id])->orderBy('id')->lockForUpdate()->get();
            $record = Transfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('cancel', $record);
            if ($record->status === TransferStatus::Cancelled) {
                return $record;
            }
            if (! $record->status->canTransitionTo(TransferStatus::Cancelled)
                || $record->paid_at !== null || $record->movements()->where('type', CashMovementType::Payout)->exists()) {
                throw ValidationException::withMessages(['cancellation' => 'Ce transfert ne peut plus être annulé. Vérifiez son statut et son paiement.']);
            }
            if ($record->feeWithdrawalItems()->exists()) {
                throw ValidationException::withMessages(['cancellation' => 'Impossible d’annuler : des frais de ce transfert ont déjà été retirés.']);
            }
            $previous = $record->status;
            $updated = Transfer::whereKey($record->id)->where('status', $previous)
                ->update(['status' => TransferStatus::Cancelled->value, 'updated_at' => now('UTC')]);
            if ($updated !== 1) {
                throw ValidationException::withMessages(['cancellation' => 'Ce transfert a déjà été traité. Actualisez la page.']);
            }
            $event = new TransferEvent;
            $event->forceFill([
                'transfer_id' => $record->id, 'user_id' => $actor->id, 'action' => 'TRANSFER_CANCELLED',
                'old_values' => ['status' => $previous->value],
                'new_values' => ['status' => TransferStatus::Cancelled->value, 'cancellation_reason' => $data['cancellation_reason']],
                'ip_address' => $ip,
            ])->save();
            $record->refresh();
            app(OperationNotifications::class)->record($record, $actor, 'cancelled');

            return $record;
        }, 3);
    }
}
