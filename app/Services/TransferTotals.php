<?php

namespace App\Services;

use App\Models\Transfer;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;

final class TransferTotals
{
    /**
     * @param  Builder<Transfer>  $query
     * @return array<string, array{received: string, fees: string, net: string, payout: string}>
     */
    public function calculate(Builder $query): array
    {
        $totals = [];
        $records = (clone $query)->select(['id', 'source_currency', 'destination_currency', 'amount_received', 'fee_amount', 'net_amount', 'payout_amount'])->lazyById(1000);

        foreach ($records as $transfer) {
            foreach ([$transfer->source_currency, $transfer->destination_currency] as $currency) {
                $totals[$currency] ??= ['received' => '0.00', 'fees' => '0.00', 'net' => '0.00', 'payout' => '0.00'];
            }
            foreach (['received' => 'amount_received', 'fees' => 'fee_amount', 'net' => 'net_amount'] as $total => $attribute) {
                $totals[$transfer->source_currency][$total] = (string) BigDecimal::of($totals[$transfer->source_currency][$total])->plus($transfer->{$attribute});
            }
            $totals[$transfer->destination_currency]['payout'] = (string) BigDecimal::of($totals[$transfer->destination_currency]['payout'])->plus($transfer->payout_amount);
        }
        ksort($totals);

        return $totals;
    }
}
