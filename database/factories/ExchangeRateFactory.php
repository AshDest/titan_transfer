<?php

namespace Database\Factories;

use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExchangeRate> */
class ExchangeRateFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'from_currency' => 'USD',
            'to_currency' => 'AED',
            'rate' => '3.670000',
            'effective_from' => now()->startOfDay(),
            'active' => true,
            'created_by' => User::factory(),
        ];
    }
}
