<?php

namespace App\Services;

use App\DTOs\TransferQuote;
use App\Enums\FeeType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class TransferCalculator
{
    public function calculate(string $amount, string $feeValue, string $rate, FeeType $feeType = FeeType::Percentage): TransferQuote
    {
        if (! preg_match('/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,2})?$/D', $amount)
            || ! preg_match($feeType === FeeType::Fixed ? '/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,2})?$/D' : '/^(0|[1-9][0-9]{0,2})(\.[0-9]{1,4})?$/D', $feeValue)
            || ! preg_match('/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,6})?$/D', $rate)) {
            throw ValidationException::withMessages(['amount_received' => 'Les montants et taux doivent être des décimaux valides.']);
        }
        $received = BigDecimal::of($amount)->toScale(2);
        $percent = BigDecimal::of($feeType === FeeType::Percentage ? $feeValue : '0')->toScale(4);
        $exchange = BigDecimal::of($rate)->toScale(6);
        if ($received->isLessThanOrEqualTo(0) || $percent->isGreaterThan(100) || $exchange->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['amount_received' => 'Le montant et le taux doivent être positifs ; les frais ne peuvent dépasser 100 %.']);
        }
        $fee = $feeType === FeeType::Fixed ? BigDecimal::of($feeValue)->toScale(2)
            : $received->multipliedBy($percent)->dividedBy(100, 2, RoundingMode::HalfUp);
        if ($fee->isGreaterThanOrEqualTo($received)) {
            throw ValidationException::withMessages(['amount_received' => 'Le montant encaissé doit être supérieur aux frais de transfert.']);
        }
        $net = $received->minus($fee);
        $payout = $net->multipliedBy($exchange)->toScale(2, RoundingMode::HalfUp);
        if ($payout->isLessThanOrEqualTo(0) || $payout->isGreaterThan('9999999999999999.99')) {
            throw ValidationException::withMessages(['amount_received' => 'Le montant à payer doit être positif et rester dans les limites autorisées.']);
        }

        return new TransferQuote((string) $received, (string) $percent, (string) $fee, (string) $net, (string) $exchange, (string) $payout, $feeType);
    }
}
