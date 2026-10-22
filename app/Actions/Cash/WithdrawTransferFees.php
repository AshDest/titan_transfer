<?php

namespace App\Actions\Cash;

use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\FeeWithdrawal;
use App\Models\FeeWithdrawalItem;
use App\Models\User;
use App\Services\FeeRevenue;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class WithdrawTransferFees
{
    public function __construct(private FeeRevenue $revenue, private RecordCashOut $cashOut) {}

    /** @param array<string, mixed> $input */
    public function handle(User $actor, CashAccount $account, array $input): FeeWithdrawal
    {
        Gate::forUser($actor)->authorize('withdrawFees', $account);
        $period = Validator::make($input, [
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'amount' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,2})?$/D', 'numeric', 'gt:0'],
            'submission_key' => ['required', 'uuid'],
        ], ['date_to.after_or_equal' => 'La date de fin doit suivre la date de début.',
            'amount.regex' => 'Saisissez un montant positif avec au maximum deux décimales.'])->validate();
        $amount = BigDecimal::of($period['amount'])->toScale(2);

        return DB::transaction(function () use ($actor, $account, $input, $period, $amount): FeeWithdrawal {
            $branch = Branch::whereKey($account->branch_id)->lockForUpdate()->firstOrFail();
            $account = CashAccount::whereKey($account->id)->lockForUpdate()->firstOrFail()->setRelation('branch', $branch);
            Gate::forUser($actor)->authorize('withdrawFees', $account);
            $existing = CashMovement::where('submission_key', $period['submission_key'])->first();
            $cashInput = [...$input, 'description' => 'Retrait des frais de transfert du '.$period['date_from'].' au '.$period['date_to']];
            if ($existing) {
                $withdrawal = FeeWithdrawal::where('cash_movement_id', $existing->id)->first();
                if (! $withdrawal || $withdrawal->period_from !== $period['date_from'] || $withdrawal->period_to !== $period['date_to']) {
                    throw ValidationException::withMessages(['submission_key' => 'Ce formulaire a déjà été utilisé. Rechargez la page.']);
                }
                $this->cashOut->handle($actor, $account, $cashInput);

                return $withdrawal;
            }
            $query = $this->revenue->query($actor, [...$period, 'branch_id' => $account->branch_id, 'currency' => $account->currency]);
            $total = $this->revenue->totals($query)[$account->id]['available'] ?? '0.00';
            if ($amount->isGreaterThan($total)) {
                throw ValidationException::withMessages(['amount' => 'Ce montant dépasse les frais encore disponibles pour cette période.']);
            }
            $movement = $this->cashOut->handle($actor, $account, $cashInput);
            $withdrawal = new FeeWithdrawal;
            $withdrawal->forceFill(['cash_movement_id' => $movement->id, 'period_from' => $period['date_from'], 'period_to' => $period['date_to']])->save();
            $remaining = $amount;
            foreach ($query->lazyById(500) as $transfer) {
                $available = BigDecimal::of($this->revenue->amounts($transfer)['available']);
                if ($available->isLessThanOrEqualTo(0)) {
                    continue;
                }
                $allocated = $available->isLessThan($remaining) ? $available : $remaining;
                $item = new FeeWithdrawalItem;
                $item->forceFill(['fee_withdrawal_id' => $withdrawal->id, 'transfer_id' => $transfer->id, 'amount' => (string) $allocated])->save();
                $remaining = $remaining->minus($allocated);
                if ($remaining->isZero()) {
                    break;
                }
            }
            if (! $remaining->isZero()) {
                throw ValidationException::withMessages(['amount' => 'Les frais disponibles ont changé. Actualisez le rapport.']);
            }

            return $withdrawal;
        }, 3);
    }
}
