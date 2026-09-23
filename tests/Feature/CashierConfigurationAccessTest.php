<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CashierConfigurationAccessTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('cashierRoles')]
    public function test_cashier_configuration_is_hidden_and_writes_are_refused_even_with_accidental_permissions(string $role): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->for(Branch::factory())->create()->assignRole($role);
        $user->givePermissionTo(['branches.manage', 'exchange-rates.manage', 'fee-rules.manage', 'exchange-rates.view', 'fee-rules.view']);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertDontSee(route('branches.index'))->assertDontSee(route('exchange-rates.index'))->assertDontSee(route('fee-rules.index'));
        foreach (['branches', 'exchange-rates', 'fee-rules'] as $module) {
            $this->get(route($module.'.create'))->assertForbidden();
            $this->post(route($module.'.store'), [])->assertForbidden();
            $this->get(route($module.'.index'))->assertOk()->assertDontSee(route($module.'.create'));
        }
        $this->assertDatabaseCount('branches', 1);
        $this->assertDatabaseCount('exchange_rates', 0);
        $this->assertDatabaseCount('transfer_fee_rules', 0);
    }

    public static function cashierRoles(): array
    {
        return [[Role::SourceCashier->value], [Role::DestinationCashier->value]];
    }
}
