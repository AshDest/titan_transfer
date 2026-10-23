<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\Role;
use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\TransferFeeRule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ModuleInterfacesTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('modulePages')]
    public function test_administrator_can_open_each_module_page(string $route): void
    {
        $user = $this->administrator();

        $this->actingAs($user)->get(route($route))->assertOk();
    }

    #[DataProvider('modulePages')]
    public function test_each_module_requires_authentication(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    public static function modulePages(): array
    {
        return array_combine($routes = [
            'customers.index', 'customers.create', 'beneficiaries.index', 'beneficiaries.create',
            'branches.index', 'branches.create', 'exchange-rates.index', 'exchange-rates.create',
            'fee-rules.index', 'fee-rules.create', 'users.index', 'roles.index',
        ], array_map(fn ($route) => [$route], $routes));
    }

    public function test_administrator_menu_links_to_all_available_modules(): void
    {
        $this->actingAs($this->administrator())->get(route('dashboard'))
            ->assertSee(route('customers.index'))->assertSee(route('beneficiaries.index'))
            ->assertSee(route('branches.index'))->assertSee(route('exchange-rates.index'))
            ->assertSee(route('fee-rules.index'))->assertSee(route('users.index'))->assertSee(route('roles.index'))
            ->assertSee('À venir');
    }

    public function test_cashier_cannot_open_administration_or_financial_creation_pages(): void
    {
        $user = $this->cashier();
        $this->actingAs($user);

        foreach (['users.index', 'roles.index', 'branches.create', 'exchange-rates.create', 'fee-rules.create'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->get(route('customers.index'))->assertDontSee(route('users.index'))->assertDontSee(route('roles.index'));
    }

    public function test_customer_search_does_not_leak_another_agencys_records(): void
    {
        $user = $this->cashier();
        Customer::factory()->create(['branch_id' => $user->branch_id, 'first_name' => 'Matched', 'last_name' => 'Visible']);
        Customer::factory()->create(['first_name' => 'Matched', 'last_name' => 'Hidden']);
        Customer::factory()->create(['branch_id' => $user->branch_id, 'first_name' => 'Unrelated', 'last_name' => 'Excluded']);

        $this->actingAs($user)->get(route('customers.index', ['q' => 'Matched']))->assertOk()
            ->assertSee('Visible')->assertDontSee('Hidden')->assertDontSee('Excluded');
    }

    public function test_beneficiaries_and_branches_are_scoped_to_the_current_agency(): void
    {
        $user = $this->cashier();
        Beneficiary::factory()->create(['branch_id' => $user->branch_id, 'last_name' => 'Visible Recipient']);
        Beneficiary::factory()->create(['last_name' => 'Hidden Recipient']);
        Branch::factory()->create(['name' => 'Hidden Agency']);

        $this->actingAs($user)->get(route('beneficiaries.index'))->assertSee('Visible Recipient')->assertDontSee('Hidden Recipient');
        $this->get(route('branches.index'))->assertSee($user->branch->name)->assertDontSee('Hidden Agency');
    }

    public function test_customer_form_saves_a_valid_customer_and_returns_to_list(): void
    {
        $user = $this->cashier();

        $this->actingAs($user)->post(route('customers.store'), [
            'branch_id' => $user->branch_id, 'first_name' => 'Test', 'last_name' => 'Sender',
            'phone' => '+243970000001', 'country' => 'DRC', 'created_by' => 9999,
        ])->assertRedirect(route('customers.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customers', ['first_name' => 'Test', 'created_by' => $user->id, 'branch_id' => $user->branch_id]);
    }

    public function test_customer_creation_refuses_a_forged_branch(): void
    {
        $user = $this->cashier();
        $other = Branch::factory()->create();

        $this->actingAs($user)->post(route('customers.store'), [
            'branch_id' => $other->id, 'first_name' => 'Test', 'last_name' => 'Sender',
            'phone' => '+243970000001', 'country' => 'DRC',
        ])->assertForbidden();

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_form_validation_retains_input_and_displays_errors(): void
    {
        $user = $this->cashier();

        $this->followingRedirects()->actingAs($user)->from(route('customers.create'))->post(route('customers.store'), [
            'branch_id' => $user->branch_id, 'first_name' => 'Retained',
        ])->assertOk()->assertSee('Retained')->assertSee('aria-invalid="true"', false)
            ->assertDontSee('last_name-error')->assertSee('phone-error')->assertSee('country-error');
        $this->assertDatabaseCount('customers', 0);
    }

    #[DataProvider('optionalLastNames')]
    public function test_customers_and_beneficiaries_can_be_registered_without_a_last_name(array $name): void
    {
        $user = $this->cashier();
        $person = ['branch_id' => $user->branch_id, 'first_name' => 'Marie', 'phone' => '+243970000001', 'country' => 'RDC', ...$name];
        $this->actingAs($user)->post(route('customers.store'), $person)->assertSessionHasNoErrors()->assertRedirect(route('customers.index'));
        $customer = Customer::sole();
        $this->assertNull($customer->last_name);
        $this->post(route('beneficiaries.store'), [...$person, 'customer_id' => $customer->id])
            ->assertSessionHasNoErrors()->assertRedirect(route('beneficiaries.index'));
        $this->assertNull(Beneficiary::sole()->last_name);
        $this->get(route('customers.index'))->assertOk()->assertSee('Marie');
        $this->get(route('beneficiaries.index'))->assertOk()->assertSee('Marie');
    }

    public static function optionalLastNames(): array
    {
        return ['omitted' => [[]], 'empty' => [['last_name' => '']], 'null' => [['last_name' => null]]];
    }

    public function test_beneficiary_can_be_created_for_a_selected_customer(): void
    {
        $user = $this->cashier();
        $customer = Customer::factory()->create(['branch_id' => $user->branch_id]);
        $this->actingAs($user)->get(route('beneficiaries.create', ['customer' => $customer->customer_number]))->assertOk()->assertSee($customer->customer_number);

        $this->post(route('beneficiaries.store'), [
            'branch_id' => $user->branch_id, 'customer_id' => $customer->id,
            'first_name' => 'Test', 'last_name' => 'Recipient', 'phone' => '+971500000001', 'country' => 'UAE',
        ])->assertRedirect(route('beneficiaries.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('beneficiaries', ['customer_id' => $customer->id, 'created_by' => $user->id]);
    }

    public function test_foreign_customer_cannot_be_selected_on_beneficiary_form(): void
    {
        $user = $this->cashier();
        $foreign = Customer::factory()->create();

        $this->actingAs($user)->get(route('beneficiaries.create', ['customer' => $foreign->customer_number]))->assertForbidden();
    }

    public function test_admin_can_create_an_agency_from_the_form(): void
    {
        $this->actingAs($this->administrator())->post(route('branches.store'), [
            'name' => 'Kigali Office', 'code' => 'KGL', 'country' => 'Rwanda', 'city' => 'Kigali',
            'default_currency' => 'RWF', 'timezone' => 'Africa/Kigali',
        ])->assertRedirect(route('branches.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('branches', ['code' => 'KGL', 'default_currency' => 'RWF']);
    }

    public function test_rate_form_accepts_browser_dates_and_preserves_old_versions(): void
    {
        $user = $this->administrator();
        $previous = ExchangeRate::factory()->create(['rate' => '3.670000']);

        $this->actingAs($user)->post(route('exchange-rates.store'), [
            'from_currency' => 'USD', 'to_currency' => 'AED', 'rate' => '3.681234',
            'effective_from' => '2026-10-01T08:30', 'effective_until' => '', 'active' => '1',
        ])->assertRedirect(route('exchange-rates.index'))->assertSessionHasNoErrors();

        $this->assertSame('3.670000', $previous->refresh()->rate);
        $new = ExchangeRate::latest('id')->first();
        $this->assertSame('3.681234', $new->rate);
        $this->assertSame('2026-10-01 08:30:00', $new->effective_from->format('Y-m-d H:i:s'));
        $this->assertSame($user->id, $new->created_by);
    }

    public function test_fee_form_stores_percentage_and_amount_bounds(): void
    {
        $this->actingAs($this->administrator())->post(route('fee-rules.store'), [
            'name' => 'New fee', 'source_currency' => 'USD', 'percentage' => '2.5123',
            'minimum_amount' => '1000.01', 'maximum_amount' => '1500.99', 'effective_from' => '2026-10-01T08:30',
            'source_branch_id' => '', 'destination_branch_id' => '', 'active' => '1',
        ])->assertRedirect(route('fee-rules.index'))->assertSessionHasNoErrors();

        $rule = TransferFeeRule::sole();
        $this->assertSame('2.5123', $rule->percentage);
        $this->assertSame('1000.01', $rule->minimum_amount);
    }

    public function test_cashier_cannot_submit_financial_configuration_or_an_agency(): void
    {
        $this->actingAs($this->cashier());
        foreach (['branches.store', 'exchange-rates.store', 'fee-rules.store'] as $route) {
            $this->post(route($route), [])->assertForbidden();
        }
        $this->assertDatabaseCount('exchange_rates', 0);
        $this->assertDatabaseCount('transfer_fee_rules', 0);
    }

    public function test_inactive_agency_cannot_access_module_pages_or_forms(): void
    {
        $user = $this->cashier();
        $user->branch->update(['status' => RecordStatus::Inactive]);

        $this->actingAs($user)->get(route('customers.index'))->assertForbidden();
        $this->post(route('customers.store'), [])->assertForbidden();
    }

    public function test_html_in_customer_names_is_escaped(): void
    {
        $user = $this->cashier();
        Customer::factory()->create(['branch_id' => $user->branch_id, 'first_name' => '<script>alert(1)</script>']);

        $this->actingAs($user)->get(route('customers.index'))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    private function administrator(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->assignRole(Role::Administrator->value);
    }

    private function cashier(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->for(Branch::factory())->create()->assignRole(Role::SourceCashier->value);
    }
}
