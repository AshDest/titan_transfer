<?php

namespace Database\Factories;

use App\Models\FeeWithdrawal;
use App\Models\FeeWithdrawalItem;
use App\Models\Transfer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FeeWithdrawalItem> */
class FeeWithdrawalItemFactory extends Factory
{
    public function definition(): array
    {
        return ['fee_withdrawal_id' => FeeWithdrawal::factory(), 'transfer_id' => Transfer::factory(), 'amount' => '1.00'];
    }
}
