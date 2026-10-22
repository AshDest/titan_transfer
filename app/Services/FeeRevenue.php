<?php

namespace App\Services;

use App\Enums\TransferStatus;
use App\Models\CashAccount;
use App\Models\Transfer;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;

final class FeeRevenue
{
    /** @param array{date_from: string, date_to: string, branch_id?: mixed, currency?: ?string} $filters */
    public function query(User $user, array $filters): Builder
    {
        return Transfer::query()->whereIn('status', [TransferStatus::Approved, TransferStatus::ReadyForPayout, TransferStatus::Paid])
            ->when(! $user->canViewAllCashAccounts(), fn ($query) => $query->where('source_branch_id', $user->branch_id))
            ->when(! empty($filters['branch_id']), fn ($query) => $query->where('source_branch_id', $filters['branch_id']))
            ->when(! empty($filters['currency']), fn ($query) => $query->where('source_currency', $filters['currency']))
            ->whereHas('depositMovement', fn ($query) => $query
                ->whereBetween('occurred_on', [$filters['date_from'], $filters['date_to']])
                ->whereColumn('cash_movements.amount', 'transfers.amount_received')
                ->whereColumn('cash_movements.currency', 'transfers.source_currency')
                ->whereHas('account', fn ($account) => $account->whereColumn('cash_accounts.branch_id', 'transfers.source_branch_id')
                    ->whereColumn('cash_accounts.currency', 'transfers.source_currency')))
            ->with(['sourceBranch', 'depositMovement.account.branch', 'feeWithdrawalItems']);
    }

    /** @return array{fees: string, withdrawn: string, available: string} */
    public function amounts(Transfer $transfer): array
    {
        $withdrawn = BigDecimal::of('0.00');
        foreach ($transfer->feeWithdrawalItems as $item) {
            $withdrawn = $withdrawn->plus($item->amount);
        }

        return ['fees' => $transfer->fee_amount, 'withdrawn' => (string) $withdrawn,
            'available' => (string) BigDecimal::of($transfer->fee_amount)->minus($withdrawn)->toScale(2)];
    }

    /** @return array<int, array{account: CashAccount, count: int, fees: string, withdrawn: string, available: string}> */
    public function totals(Builder $query): array
    {
        $totals = [];
        foreach ((clone $query)->lazyById(500) as $transfer) {
            $account = $transfer->depositMovement->account;
            $totals[$account->id] ??= ['account' => $account, 'count' => 0, 'fees' => '0.00', 'withdrawn' => '0.00', 'available' => '0.00'];
            $totals[$account->id]['count']++;
            foreach ($this->amounts($transfer) as $key => $amount) {
                $totals[$account->id][$key] = (string) BigDecimal::of($totals[$account->id][$key])->plus($amount)->toScale(2);
            }
        }

        return $totals;
    }
}
