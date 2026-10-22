<?php

namespace Database\Factories;

use App\Enums\FeeType;
use App\Models\TransferFeeRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TransferFeeRule> */
class TransferFeeRuleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Standard fee',
            'source_currency' => 'USD',
            'percentage' => '3.0000',
            'active' => true,
            'effective_from' => now()->startOfDay(),
            'created_by' => User::factory(),
        ];
    }

    public function fixed(string $amount = '5.00'): static
    {
        return $this->state(fn (): array => ['fee_type' => FeeType::Fixed, 'fixed_amount' => $amount, 'percentage' => '0.0000']);
    }
}
