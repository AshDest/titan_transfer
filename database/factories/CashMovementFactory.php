<?php

namespace Database\Factories;

use App\Enums\CashMovementType;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Transfer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CashMovement> */
class CashMovementFactory extends Factory
{
    public function definition(): array
    {
        return ['transfer_id' => Transfer::factory(), 'type' => CashMovementType::Deposit,
            'cash_account_id' => fn (array $a) => CashAccount::factory()->create(['branch_id' => Transfer::findOrFail($a['transfer_id'])->source_branch_id, 'balance' => '100.00'])->id,
            'currency' => 'USD', 'amount' => '100.00', 'balance_before' => '0.00', 'balance_after' => '100.00',
            'description' => 'Encaissement de transfert', 'created_by' => fn (array $a) => Transfer::findOrFail($a['transfer_id'])->created_by];
    }
}
