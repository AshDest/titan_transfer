<?php

namespace Database\Seeders;

use App\Enums\Permission as Ability;
use App\Enums\Role as UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function (): void {
            foreach (Ability::cases() as $ability) {
                Permission::findOrCreate($ability->value, 'web');
            }

            $common = [Ability::DashboardView, Ability::BranchesView, Ability::TransfersView, Ability::CashView];
            $registration = [Ability::CustomersView, Ability::CustomersCreate, Ability::BeneficiariesView, Ability::BeneficiariesCreate];
            $roles = [
                UserRole::Administrator->value => Ability::cases(),
                UserRole::SourceCashier->value => [...$common, ...$registration, Ability::TransfersCreate, Ability::ExchangeRatesView, Ability::FeeRulesView, Ability::CashWithdraw],
                UserRole::DestinationCashier->value => [...$common, Ability::TransfersPay, Ability::CashWithdraw, Ability::CashDeposit],
                UserRole::Manager->value => [...$common, ...$registration, Ability::TransfersApprove, Ability::TransfersReject, Ability::TransfersCancel, Ability::ReportsView],
            ];

            foreach ($roles as $name => $abilities) {
                $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
                if ($role->wasRecentlyCreated) {
                    $role->givePermissionTo(array_map(fn (Ability $ability): string => $ability->value, $abilities));
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
