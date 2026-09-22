<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => '+243'.fake()->numerify('#########'),
            'country' => fake()->country(),
            'city' => fake()->city(),
            'email' => fake()->safeEmail(),
            'status' => RecordStatus::Active,
            'created_by' => User::factory(),
        ];
    }
}
