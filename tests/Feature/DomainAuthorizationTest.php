<?php

namespace Tests\Feature;

use App\Actions\Customers\RegisterCustomer;
use App\Actions\Finance\RecordExchangeRate;
use App\Enums\RecordStatus;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DomainAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('restrictedRoles')]
    public function test_non_administrators_cannot_configure_rates_or_settings(string $role): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create()->assignRole($role);
        $this->assertFalse($user->can('settings.manage'));
        $this->assertFalse($user->can('users.manage'));
        $this->assertFalse($user->can('roles.manage'));
        $this->assertFalse($user->can('fee-rules.manage'));

        try {
            app(RecordExchangeRate::class)->handle($user, []);
            $this->fail('Rate creation must be forbidden.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('exchange_rates', 0);
        }
    }

    public static function restrictedRoles(): array
    {
        return [
            'source cashier' => [Role::SourceCashier->value],
            'destination cashier' => [Role::DestinationCashier->value],
            'manager' => [Role::Manager->value],
        ];
    }

    public function test_source_cashier_has_no_payout_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $cashier = User::factory()->create()->assignRole(Role::SourceCashier->value);

        $this->assertTrue($cashier->can('transfers.create'));
        $this->assertFalse($cashier->can('transfers.pay'));
        $this->assertFalse($cashier->can('transfers.reverse'));
    }

    public function test_destination_cashier_has_payout_permission_but_cannot_register_transfers(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $cashier = User::factory()->create()->assignRole(Role::DestinationCashier->value);

        $this->assertTrue($cashier->can('transfers.pay'));
        $this->assertFalse($cashier->can('transfers.create'));
    }

    public function test_customer_access_is_scoped_to_the_cashiers_branch(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $customer = Customer::factory()->create();
        $cashier = User::factory()->for($customer->branch)->create()->assignRole(Role::SourceCashier->value);
        $foreign = Customer::factory()->create();

        $this->assertTrue(Gate::forUser($cashier)->allows('view', $customer));
        $this->assertFalse(Gate::forUser($cashier)->allows('view', $foreign));

        try {
            app(RegisterCustomer::class)->handle($cashier, $foreign->branch, []);
            $this->fail('Cross-branch writes must be forbidden.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('customers', 2);
        }
    }

    public function test_cashier_without_a_branch_cannot_register_customers(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create()->assignRole(Role::SourceCashier->value);
        $branch = Branch::factory()->create();

        $this->assertFalse(Gate::forUser($user)->allows('create', [Customer::class, $branch]));
    }

    public function test_inactive_branches_refuse_customer_registration_even_for_administrators(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create()->assignRole(Role::Administrator->value);
        $branch = Branch::factory()->create(['status' => RecordStatus::Inactive]);

        $this->assertFalse(Gate::forUser($admin)->allows('create', [Customer::class, $branch]));
    }

    public function test_inactive_branch_records_remain_readable_for_history(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $branch = Branch::factory()->create(['status' => RecordStatus::Inactive]);
        $customer = Customer::factory()->for($branch)->create();
        $cashier = User::factory()->for($branch)->create()->assignRole(Role::SourceCashier->value);

        $this->assertTrue(Gate::forUser($cashier)->allows('view', $customer));
        $this->assertFalse(Gate::forUser($cashier)->allows('create', [Customer::class, $branch]));
    }

    public function test_administrator_can_access_another_active_branch_without_bypassing_policies(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create()->assignRole(Role::Administrator->value);
        $customer = Customer::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('view', $customer));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $customer));
    }

    public function test_unassigned_user_and_guest_have_no_privileged_access(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $branch = Branch::factory()->create();

        $this->assertFalse($user->can('settings.manage'));
        $this->assertFalse(Gate::forUser($user)->allows('create', [Customer::class, $branch]));
        $this->assertFalse(Gate::allows('create', Branch::class));
    }
}
