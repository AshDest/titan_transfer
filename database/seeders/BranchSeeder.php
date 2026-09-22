<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        Branch::firstOrCreate(['code' => 'GOM'], [
            'name' => 'Goma Office',
            'country' => 'Democratic Republic of Congo',
            'city' => 'Goma',
            'default_currency' => 'USD',
            'timezone' => 'Africa/Lubumbashi',
        ]);

        Branch::firstOrCreate(['code' => 'DXB'], [
            'name' => 'Dubai Office',
            'country' => 'United Arab Emirates',
            'city' => 'Dubai',
            'default_currency' => 'AED',
            'timezone' => 'Asia/Dubai',
        ]);
    }
}
