<?php

namespace Database\Factories;

use App\Models\Transfer;
use App\Models\TransferEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TransferEvent> */
class TransferEventFactory extends Factory
{
    public function definition(): array
    {
        return ['transfer_id' => Transfer::factory(), 'user_id' => fn (array $a) => Transfer::findOrFail($a['transfer_id'])->created_by,
            'action' => 'TRANSFER_CREATED', 'new_values' => ['status' => 'PENDING'], 'ip_address' => '127.0.0.1'];
    }
}
