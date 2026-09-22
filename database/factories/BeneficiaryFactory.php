<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Beneficiary> */
class BeneficiaryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => '+971'.fake()->numerify('#########'),
            'country' => fake()->country(),
            'status' => RecordStatus::Active,
            'created_by' => User::factory(),
        ];
    }
}
