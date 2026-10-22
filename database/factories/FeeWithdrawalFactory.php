<?php

namespace Database\Factories;

use App\Enums\CashMovementType;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\FeeWithdrawal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FeeWithdrawal> */
class FeeWithdrawalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cash_movement_id' => CashMovement::factory()->state(['type' => CashMovementType::CashOut, 'transfer_id' => null, 'cash_account_id' => CashAccount::factory(), 'created_by' => User::factory(), 'recipient_name' => fake()->name(), 'submission_key' => (string) Str::uuid()]),
            'period_from' => now()->startOfMonth()->toDateString(),
            'period_to' => now()->toDateString(),
        ];
    }
}
