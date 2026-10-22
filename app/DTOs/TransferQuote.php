<?php

namespace App\DTOs;

use App\Enums\FeeType;

final readonly class TransferQuote
{
    public function __construct(
        public string $amountReceived,
        public string $feePercentage,
        public string $feeAmount,
        public string $netAmount,
        public string $exchangeRate,
        public string $payoutAmount,
        public FeeType $feeType = FeeType::Percentage,
    ) {}

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'amount_received' => $this->amountReceived,
            'fee_type' => $this->feeType->value,
            'fee_percentage' => $this->feePercentage,
            'fee_amount' => $this->feeAmount,
            'net_amount' => $this->netAmount,
            'exchange_rate' => $this->exchangeRate,
            'payout_amount' => $this->payoutAmount,
        ];
    }
}
