<?php

namespace Database\Factories;

use App\Enums\TransferStatus;
use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Transfer;
use App\Models\TransferFeeRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Transfer> */
class TransferFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(), 'submission_key' => (string) Str::uuid(), 'reference' => 'TT-'.strtoupper(Str::random(12)),
            'source_branch_id' => Branch::factory()->state(['default_currency' => 'USD']),
            'destination_branch_id' => Branch::factory()->state(['default_currency' => 'AED']),
            'sender_id' => fn (array $attributes) => Customer::factory()->create(['branch_id' => $attributes['source_branch_id']])->id,
            'beneficiary_id' => fn (array $attributes) => Beneficiary::factory()->create(['branch_id' => $attributes['source_branch_id'], 'customer_id' => $attributes['sender_id']])->id,
            'created_by' => User::factory(), 'exchange_rate_id' => ExchangeRate::factory(), 'transfer_fee_rule_id' => TransferFeeRule::factory(),
            'source_currency' => 'USD', 'destination_currency' => 'AED', 'amount_received' => '100.00',
            'fee_percentage' => '3.0000', 'fee_amount' => '3.00', 'net_amount' => '97.00', 'exchange_rate' => '3.670000', 'payout_amount' => '355.99',
            'status' => TransferStatus::Pending,
            'parties_snapshot' => fn (array $attributes) => [
                'sender' => Customer::findOrFail($attributes['sender_id'])->only(['first_name', 'last_name', 'phone', 'country', 'city']),
                'beneficiary' => Beneficiary::findOrFail($attributes['beneficiary_id'])->only(['first_name', 'last_name', 'phone', 'country', 'city']),
            ],
        ];
    }
}
