<?php

namespace App\Actions\Transfers;

use App\Actions\Beneficiaries\RegisterBeneficiary;
use App\Actions\Customers\RegisterCustomer;
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
use App\Services\TransferPricing;
use App\Validation\TransferInput;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateTransfer
{
    public function __construct(private TransferInput $input, private TransferPricing $pricing) {}

    /** @return array<string, mixed> */
    public function review(User $actor, array $input): array
    {
        $prepared = $this->input->prepare($actor, $input);
        $pricing = $this->pricing->quote($prepared['source'], $prepared['destination'], $prepared['data']['amount_received']);
        $snapshot = [];
        foreach (['sender', 'beneficiary'] as $party) {
            $snapshot[$party] = $prepared[$party]?->only(['first_name', 'last_name', 'phone', 'country', 'city'])
                ?? collect($prepared['data'][$party])->only(['first_name', 'last_name', 'phone', 'country', 'city'])->all();
        }
        $fingerprint = hash('sha256', json_encode([
            $pricing['quote']->attributes(), $pricing['rate']?->id, $pricing['fee']->id,
            $prepared['source']->default_currency, $prepared['destination']->default_currency, $snapshot,
        ], JSON_THROW_ON_ERROR));

        return [...$prepared, ...$pricing, 'snapshot' => $snapshot, 'fingerprint' => $fingerprint];
    }

    public function handle(User $actor, array $input, string $fingerprint, string $submissionKey, ?string $ip): Transfer
    {
        $prepared = $this->input->prepare($actor, $input);

        return DB::transaction(function () use ($actor, $input, $fingerprint, $submissionKey, $ip, $prepared): Transfer {
            Branch::whereKey([$prepared['source']->id, $prepared['destination']->id])->orderBy('id')->lockForUpdate()->get();
            $existing = Transfer::where('submission_key', $submissionKey)->first();
            if ($existing) {
                abort_unless($existing->created_by === $actor->id, 403);
                Gate::forUser($actor)->authorize('view', $existing);

                return $existing;
            }
            $review = $this->review($actor, $input);
            if (! hash_equals($review['fingerprint'], $fingerprint)) {
                throw ValidationException::withMessages(['review_token' => 'Les informations ou les tarifs ont changé. Vérifiez à nouveau l’opération avant de confirmer.']);
            }
            $source = $review['source'];
            $account = CashAccount::where('branch_id', $source->id)->where('currency', $source->default_currency)->lockForUpdate()->first();
            if (! $account) {
                $account = new CashAccount;
                $account->forceFill(['branch_id' => $source->id, 'name' => 'Caisse '.$source->code,
                    'currency' => $source->default_currency, 'opening_balance' => '0.00', 'balance' => '0.00', 'status' => RecordStatus::Active])->save();
            }
            if ($account->status !== RecordStatus::Active) {
                throw ValidationException::withMessages(['review_token' => 'La caisse de cette agence est inactive.']);
            }
            $before = $account->balance;
            $after = BigDecimal::of($before)->plus($review['quote']->amountReceived)->toScale(2);
            if ($after->isGreaterThan('9999999999999999.99')) {
                throw ValidationException::withMessages(['amount_received' => 'Le solde maximal de la caisse serait dépassé.']);
            }
            $sender = $review['sender'] ?? app(RegisterCustomer::class)->handle($actor, $source, $review['data']['sender']);
            $beneficiary = $review['beneficiary'] ?? app(RegisterBeneficiary::class)->handle($actor, $source, [
                ...$review['data']['beneficiary'], 'customer_id' => $sender->id,
            ]);
            $transfer = new Transfer;
            $transfer->forceFill([
                ...$review['quote']->attributes(), 'uuid' => (string) Str::uuid(),
                'reference' => 'TT-'.strtoupper(Str::random(12)), 'submission_key' => $submissionKey,
                'sender_id' => $sender->id, 'beneficiary_id' => $beneficiary->id,
                'source_branch_id' => $source->id, 'destination_branch_id' => $review['destination']->id,
                'source_currency' => $source->default_currency, 'destination_currency' => $review['destination']->default_currency,
                'created_by' => $actor->id, 'exchange_rate_id' => $review['rate']?->id,
                'transfer_fee_rule_id' => $review['fee']->id, 'parties_snapshot' => $review['snapshot'],
                'status' => TransferStatus::Pending, 'notes' => $review['data']['notes'] ?? null,
            ])->save();
            $account->forceFill(['balance' => (string) $after])->save();
            $movement = new CashMovement;
            $movement->forceFill([
                'cash_account_id' => $account->id, 'transfer_id' => $transfer->id, 'type' => CashMovementType::Deposit,
                'currency' => $source->default_currency, 'amount' => $review['quote']->amountReceived,
                'balance_before' => $before, 'balance_after' => (string) $after,
                'description' => 'Encaissement '.$transfer->reference, 'created_by' => $actor->id,
            ])->save();
            $event = new TransferEvent;
            $event->forceFill([
                'transfer_id' => $transfer->id, 'user_id' => $actor->id, 'action' => 'TRANSFER_CREATED',
                'new_values' => [...$review['quote']->attributes(), 'status' => TransferStatus::Pending->value, 'cash_movement_id' => $movement->id],
                'ip_address' => $ip,
            ])->save();

            app(OperationNotifications::class)->record($transfer, $actor);

            return $transfer;
        }, 3);
    }
}
