<?php

namespace Tests\Feature;

use App\Actions\Beneficiaries\RegisterBeneficiary;
use App\Actions\Branches\CreateBranch;
use App\Actions\Customers\RegisterCustomer;
use App\Enums\RecordStatus;
use App\Enums\Role;
use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevelopmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role as PermissionRole;
use Tests\TestCase;

class DomainFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_are_repeatable_and_preserve_custom_permissions_and_passwords(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@example.test')->firstOrFail();
        $password = $admin->password;
        PermissionRole::findByName(Role::Manager->value)->revokePermissionTo('transfers.approve');

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('branches', 2);
        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseCount('roles', 4);
        $this->assertDatabaseCount('exchange_rates', 1);
        $this->assertDatabaseCount('transfer_fee_rules', 1);
        $this->assertSame($password, $admin->refresh()->password);
        $this->assertFalse(PermissionRole::findByName(Role::Manager->value)->hasPermissionTo('transfers.approve'));
        $this->assertDatabaseHas('branches', ['code' => 'GOM', 'timezone' => 'Africa/Lubumbashi']);
        $this->assertDatabaseHas('branches', ['code' => 'DXB', 'timezone' => 'Asia/Dubai']);
    }

    public function test_demo_seeder_refuses_production_without_writing_records(): void
    {
        $this->app->instance('env', 'production');

        try {
            app(DevelopmentSeeder::class)->run();
            $this->fail('Demo seeding must be refused in production.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('restricted', $exception->getMessage());
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('branches', 0);
        }
    }

    public function test_production_seeding_creates_foundation_without_demo_users_or_prices(): void
    {
        $this->app->instance('env', 'production');

        app(DatabaseSeeder::class)->run();

        $this->assertDatabaseCount('branches', 2);
        $this->assertDatabaseCount('roles', 4);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('exchange_rates', 0);
        $this->assertDatabaseCount('transfer_fee_rules', 0);
    }

    public function test_administrator_can_register_an_additional_branch(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create()->assignRole(Role::Administrator->value);

        $branch = app(CreateBranch::class)->handle($admin, [
            'name' => 'Kigali Office', 'code' => 'KGL', 'country' => 'Rwanda',
            'city' => 'Kigali', 'default_currency' => 'RWF', 'timezone' => 'Africa/Kigali',
        ]);

        $this->assertModelExists($branch);
        $this->assertSame(RecordStatus::Active, $branch->status);
        $this->assertSame('RWF', $branch->default_currency);
    }

    #[DataProvider('invalidBranches')]
    public function test_invalid_branch_is_rejected(array $changes, string $field): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create()->assignRole(Role::Administrator->value);
        Branch::factory()->create(['code' => 'EXISTING']);

        try {
            app(CreateBranch::class)->handle($admin, [
                'name' => 'Kigali Office', 'code' => 'KGL', 'country' => 'Rwanda',
                'city' => 'Kigali', 'default_currency' => 'RWF', 'timezone' => 'Africa/Kigali',
                ...$changes,
            ]);
            $this->fail('Invalid branch data must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
            $this->assertDatabaseCount('branches', 1);
        }
    }

    public static function invalidBranches(): array
    {
        return [
            'duplicate code' => [['code' => 'EXISTING'], 'code'],
            'invalid code' => [['code' => '../KGL'], 'code'],
            'invalid timezone' => [['timezone' => 'Not/AZone'], 'timezone'],
            'invalid currency' => [['default_currency' => 'usd'], 'default_currency'],
            'missing name' => [['name' => ''], 'name'],
        ];
    }

    public function test_beneficiary_can_be_registered_without_a_sender(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $branch = Branch::factory()->create();
        $cashier = User::factory()->for($branch)->create()->assignRole(Role::SourceCashier->value);

        $beneficiary = app(RegisterBeneficiary::class)->handle($cashier, $branch, [
            'first_name' => 'Test', 'last_name' => 'Receiver', 'phone' => '+971500000001', 'country' => 'UAE',
        ]);

        $this->assertModelExists($beneficiary);
        $this->assertNull($beneficiary->customer_id);
    }

    public function test_customer_and_reusable_beneficiary_are_registered_in_the_authorized_branch(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $branch = Branch::factory()->create();
        $cashier = User::factory()->for($branch)->create()->assignRole(Role::SourceCashier->value);

        $customer = app(RegisterCustomer::class)->handle($cashier, $branch, [
            'first_name' => 'Test', 'last_name' => 'Sender', 'phone' => '+243970000001', 'country' => 'DRC',
            'created_by' => 99999, 'branch_id' => 99999, 'status' => 'INACTIVE',
            'customer_number' => 'FORGED', 'id_document_path' => '/secret.txt',
        ]);
        $beneficiary = app(RegisterBeneficiary::class)->handle($cashier, $branch, [
            'first_name' => 'Test', 'last_name' => 'Receiver', 'phone' => '+971500000001', 'country' => 'UAE',
            'customer_id' => $customer->id, 'created_by' => 99999,
        ]);

        $this->assertMatchesRegularExpression('/^CUS-[0-9A-HJKMNP-TV-Z]{26}$/', $customer->customer_number);
        $this->assertSame(RecordStatus::Active, $customer->status);
        $this->assertNull($customer->id_document_path);
        $this->assertTrue($customer->branch->is($branch));
        $this->assertTrue($customer->createdBy->is($cashier));
        $this->assertTrue($customer->beneficiaries->sole()->is($beneficiary));
        $this->assertTrue($beneficiary->customer->is($customer));
        $this->assertTrue($beneficiary->createdBy->is($cashier));
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('beneficiaries', 1);
    }

    public function test_beneficiary_cannot_reference_a_customer_from_another_branch(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $branch = Branch::factory()->create();
        $cashier = User::factory()->for($branch)->create()->assignRole(Role::SourceCashier->value);
        $foreignCustomer = Customer::factory()->create();

        try {
            app(RegisterBeneficiary::class)->handle($cashier, $branch, [
                'first_name' => 'Test', 'last_name' => 'Receiver', 'phone' => '+971500000001',
                'country' => 'UAE', 'customer_id' => $foreignCustomer->id,
            ]);
            $this->fail('A cross-branch customer must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer_id', $exception->errors());
            $this->assertDatabaseCount('beneficiaries', 0);
        }
    }

    public function test_invalid_customer_registration_does_not_write_a_record(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $branch = Branch::factory()->create();
        $cashier = User::factory()->for($branch)->create()->assignRole(Role::SourceCashier->value);

        try {
            app(RegisterCustomer::class)->handle($cashier, $branch, [
                'first_name' => '', 'last_name' => 'Sender', 'phone' => 'invalid', 'country' => 'DRC',
                'email' => 'invalid', 'id_number' => 'ID-123',
            ]);
            $this->fail('Invalid customer data must be refused.');
        } catch (ValidationException $exception) {
            foreach (['first_name', 'phone', 'email', 'id_type'] as $field) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
            $this->assertDatabaseCount('customers', 0);
        }
    }

    public function test_referenced_customer_cannot_be_deleted(): void
    {
        $customer = Customer::factory()->create();
        Beneficiary::factory()->for($customer)->create(['branch_id' => $customer->branch_id]);

        $this->expectException(QueryException::class);

        $customer->delete();
    }

    public function test_referenced_branch_cannot_be_deleted(): void
    {
        $branch = Branch::factory()->create();
        User::factory()->for($branch)->create();

        $this->expectException(QueryException::class);

        $branch->delete();
    }

    public function test_author_of_financial_configuration_cannot_be_deleted(): void
    {
        $rate = ExchangeRate::factory()->create();

        $this->expectException(QueryException::class);

        $rate->createdBy->delete();
    }

    public function test_customer_numbers_are_unique_and_identity_fields_are_not_serialized(): void
    {
        $customers = Customer::factory()->count(3)->create(['id_number' => 'PRIVATE', 'id_document_path' => 'private/document.pdf']);

        $this->assertCount(3, $customers->pluck('customer_number')->unique());
        $this->assertArrayNotHasKey('id_number', $customers->first()->toArray());
        $this->assertArrayNotHasKey('id_document_path', $customers->first()->toArray());
    }
}
