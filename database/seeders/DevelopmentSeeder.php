<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\ExchangeRate;
use App\Models\TransferFeeRule;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo data is restricted to local and testing environments.');
        }

        $this->call([RolesAndPermissionsSeeder::class, BranchSeeder::class]);

        DB::transaction(function (): void {
            $source = Branch::where('code', 'GOM')->firstOrFail();
            $destination = Branch::where('code', 'DXB')->firstOrFail();
            $users = [
                ['admin@example.test', 'Demo Administrator', Role::Administrator, null],
                ['goma.cashier@example.test', 'Demo Source Cashier', Role::SourceCashier, $source],
                ['dubai.cashier@example.test', 'Demo Destination Cashier', Role::DestinationCashier, $destination],
                ['manager@example.test', 'Demo Manager', Role::Manager, $source],
            ];

            foreach ($users as [$email, $name, $role, $branch]) {
                $user = User::firstOrCreate(['email' => $email], [
                    'name' => $name,
                    'password' => Str::password(40),
                ]);
                if ($user->wasRecentlyCreated) {
                    $user->branch()->associate($branch);
                    $user->save();
                    $user->assignRole($role->value);
                }
            }

            $admin = User::where('email', 'admin@example.test')->firstOrFail();
            ExchangeRate::firstOrCreate([
                'from_currency' => 'USD',
                'to_currency' => 'AED',
                'effective_from' => '2026-09-22 00:00:00',
            ], [
                'rate' => '3.670000',
                'active' => true,
                'created_by' => $admin->id,
            ]);

            TransferFeeRule::firstOrCreate(['name' => 'Demo Goma to Dubai — 3%'], [
                'source_branch_id' => $source->id,
                'destination_branch_id' => $destination->id,
                'source_currency' => 'USD',
                'percentage' => '3.0000',
                'active' => true,
                'effective_from' => '2026-09-22 00:00:00',
                'created_by' => $admin->id,
            ]);
        });
    }
}
