<?php

namespace App\Actions\Transfers;

use App\Enums\CashMovementType;
use App\Enums\RecordStatus;
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

final class ReviewTransfer
{
    public function handle(User $actor, Transfer $transfer, TransferStatus $decision, ?string $reason = null, ?string $ip = null): Transfer
    {
        if (! in_array($decision, [TransferStatus::Approved, TransferStatus::Rejected], true)) {
            throw new \InvalidArgumentException('Only approval and rejection are supported.');
        }
        $ability = $decision === TransferStatus::Approved ? 'approve' : 'reject';
        Gate::forUser($actor)->authorize($ability, $transfer);
        if ($decision === TransferStatus::Rejected) {
            $reason = trim($reason ?? '');
            Validator::make(['rejection_reason' => $reason], ['rejection_reason' => ['required', 'string', 'min:5', 'max:1000']], [
                'rejection_reason.required' => 'Indiquez le motif du rejet.',
                'rejection_reason.min' => 'Le motif doit contenir au moins 5 caractères.',
                'rejection_reason.max' => 'Le motif ne peut dépasser 1 000 caractères.',
            ])->validate();
        }

        return DB::transaction(function () use ($actor, $transfer, $decision, $reason, $ip, $ability): Transfer {
            Branch::whereKey([$transfer->source_branch_id, $transfer->destination_branch_id])->orderBy('id')->lockForUpdate()->get();
            $record = Transfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize($ability, $record);
            if ($record->status === $decision) {
                return $record;
            }
            if ($record->status !== TransferStatus::Pending) {
                throw ValidationException::withMessages(['decision' => 'Ce transfert a déjà été traité. Actualisez la page.']);
            }
            if ($decision === TransferStatus::Approved) {
                if ($record->destinationBranch->status !== RecordStatus::Active) {
                    throw ValidationException::withMessages(['decision' => 'L’agence de retrait est inactive. Impossible d’approuver ce transfert.']);
                }
                $deposit = $record->movements()->where('type', CashMovementType::Deposit)->first();
                if (! $deposit || $deposit->amount !== $record->amount_received || $deposit->currency !== $record->source_currency) {
                    throw ValidationException::withMessages(['decision' => 'L’encaissement doit être vérifié avant l’approbation.']);
                }
            }
            $at = now();
            $changes = $decision === TransferStatus::Approved
                ? ['approved_by' => $actor->id, 'approved_at' => $at]
                : ['rejected_by' => $actor->id, 'rejected_at' => $at, 'rejection_reason' => $reason];
            /** Only this guarded transition updates review metadata; financial snapshots remain immutable. */
            $updated = Transfer::whereKey($record->id)->where('status', TransferStatus::Pending)
                ->update([...$changes, 'status' => $decision->value, 'updated_at' => $at]);
            if ($updated !== 1) {
                throw ValidationException::withMessages(['decision' => 'Une autre personne a déjà traité ce transfert. Actualisez la page.']);
            }
            $record->refresh();
            $event = new TransferEvent;
            $event->forceFill([
                'transfer_id' => $record->id, 'user_id' => $actor->id,
                'action' => $decision === TransferStatus::Approved ? 'TRANSFER_APPROVED' : 'TRANSFER_REJECTED',
                'old_values' => ['status' => TransferStatus::Pending->value],
                'new_values' => [...$changes, 'status' => $decision->value], 'ip_address' => $ip,
            ])->save();
            app(OperationNotifications::class)->record($record, $actor, $decision === TransferStatus::Approved ? 'approved' : 'rejected');

            return $record;
        }, 3);
    }
}
