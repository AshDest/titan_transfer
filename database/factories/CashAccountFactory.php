<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Branch;
use App\Models\CashAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CashAccount> */
class CashAccountFactory extends Factory
{
    public function definition(): array
    {
        return ['branch_id' => Branch::factory(), 'name' => 'Caisse principale', 'currency' => 'USD',
            'opening_balance' => '0.00', 'balance' => '0.00', 'status' => RecordStatus::Active];
    }
}
