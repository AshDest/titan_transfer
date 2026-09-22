<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->city().' Office',
            'code' => strtoupper(fake()->unique()->bothify('????##')),
            'country' => fake()->country(),
            'city' => fake()->city(),
            'default_currency' => 'USD',
            'timezone' => 'UTC',
            'status' => RecordStatus::Active,
        ];
    }
}
