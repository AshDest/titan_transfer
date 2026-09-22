<?php

namespace Tests\Unit;

use App\Services\TransferCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TransferCalculatorTest extends TestCase
{
    #[DataProvider('calculations')]
    public function test_fees_are_deducted_before_conversion_with_exact_decimal_rounding(string $amount, string $percentage, string $rate, string $fee, string $net, string $payout): void
    {
        $quote = (new TransferCalculator)->calculate($amount, $percentage, $rate);
        $this->assertSame($fee, $quote->feeAmount);
        $this->assertSame($net, $quote->netAmount);
        $this->assertSame($payout, $quote->payoutAmount);
    }

    public static function calculations(): array
    {
        return [
            ['100.00', '3.0000', '3.670000', '3.00', '97.00', '355.99'],
            ['1.00', '0.5000', '1.005000', '0.01', '0.99', '0.99'],
            ['0.10', '0', '1.050000', '0.00', '0.10', '0.11'],
            ['9999999999999999.99', '0', '1', '0.00', '9999999999999999.99', '9999999999999999.99'],
        ];
    }
}
