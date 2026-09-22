<?php

namespace Database\Factories;

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
}
