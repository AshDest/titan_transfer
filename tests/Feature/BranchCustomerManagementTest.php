<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\Role;
use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\Transfer;
use App\Models\TransferFeeRule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BranchCustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_administrator_can_edit_an_agency_and_notifications_use_the_update_event(): void
    {
        $admin = $this->administrator();
        $branch = Branch::factory()->create();
        $this->actingAs($admin)->get(route('branches.index'))->assertSee(route('branches.edit', $branch));
        $this->get(route('branches.edit', $branch))->assertOk()->assertSee($branch->name)->assertSee(route('branches.destroy', $branch));

        $this->put(route('branches.update', $branch), $this->branchInput($branch, [
            'name' => 'Agence modifiée', 'city' => 'Lubumbashi', 'default_currency' => 'CDF', 'status' => 'INACTIVE',
        ]))->assertRedirect(route('branches.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'name' => 'Agence modifiée', 'default_currency' => 'CDF', 'status' => 'INACTIVE']);
        $notification = $admin->notifications()->sole();
        $this->assertSame('updated', $notification->data['event']);
        $this->assertSame('Agence modifiée', $notification->data['title']);
        $this->post(route('notifications.open', $notification))->assertRedirect(route('branches.index', ['q' => $branch->code]));
    }

    public function test_customer_edits_preserve_transfer_snapshot_and_protected_fields(): void
    {
        $admin = $this->administrator();
        $transfer = Transfer::factory()->create();
        $customer = Customer::findOrFail($transfer->sender_id);
        $snapshot = $transfer->parties_snapshot;
        $protected = $customer->only(['customer_number', 'branch_id', 'created_by']);
        $this->actingAs($admin)->get(route('customers.index'))->assertSee(route('customers.edit', $customer));
        $this->get(route('customers.edit', $customer))->assertOk()->assertSee($customer->first_name);

        $this->put(route('customers.update', $customer), $this->customerInput($customer, [
            'first_name' => 'Nouveau prénom', 'phone' => '+243970000001', 'status' => 'INACTIVE',
            'branch_id' => $transfer->destination_branch_id, 'customer_number' => 'FORGED', 'created_by' => $admin->id,
        ]))->assertRedirect(route('customers.index'))->assertSessionHasNoErrors();

        $this->assertSame('Nouveau prénom', $customer->refresh()->first_name);
        $this->assertSame(RecordStatus::Inactive, $customer->status);
        $this->assertSame($protected, $customer->only(array_keys($protected)));
        $this->assertSame($snapshot, $transfer->refresh()->parties_snapshot);
        $this->assertSame('355.99', $transfer->payout_amount);
        $this->assertSame('Client modifié', $admin->notifications()->sole()->data['title']);
    }

    public function test_administrator_can_clear_a_customer_last_name(): void
    {
        $customer = Customer::factory()->create();
        $this->actingAs($this->administrator())->put(route('customers.update', $customer), $this->customerInput($customer, ['last_name' => '']))
            ->assertSessionHasNoErrors()->assertRedirect(route('customers.index'));
        $this->assertNull($customer->refresh()->last_name);
    }

    public function test_unused_records_can_be_deleted_only_after_confirmation(): void
    {
        $admin = $this->administrator();
        $customer = Customer::factory()->create();
        $branch = $customer->branch;
        $this->actingAs($admin);
        foreach (['customers' => $customer, 'branches' => $branch] as $resource => $record) {
            $this->delete(route($resource.'.destroy', $record))->assertRedirect(route($resource.'.edit', $record))->assertSessionHasErrors('confirmation');
            $this->assertModelExists($record);
            $this->delete(route($resource.'.destroy', $record), ['confirmation' => '1'])->assertRedirect(route($resource.'.index'))->assertSessionHasNoErrors();
            $this->assertModelMissing($record);
        }
    }

    #[DataProvider('branchDependencies')]
    public function test_agencies_with_dependents_cannot_be_deleted(string $dependency): void
    {
        $branch = Branch::factory()->create();
        $this->addBranchDependency($branch, $dependency);
        $this->actingAs($this->administrator())->followingRedirects()
            ->delete(route('branches.destroy', $branch), ['confirmation' => '1'])
            ->assertOk()->assertSee('Suppression impossible')->assertSee('Vous pouvez la désactiver.');
        $this->assertModelExists($branch);
    }

    public static function branchDependencies(): array
    {
        return array_map(fn (string $type): array => [$type], ['user', 'customer', 'beneficiary', 'cash', 'source_transfer', 'destination_transfer', 'source_fee', 'destination_fee']);
    }

    #[DataProvider('financialDependencies')]
    public function test_currency_changes_are_refused_after_financial_configuration_or_activity(string $dependency): void
    {
        $branch = Branch::factory()->create();
        $this->addBranchDependency($branch, $dependency);
        $this->actingAs($this->administrator())->put(route('branches.update', $branch), $this->branchInput($branch, ['default_currency' => 'EUR']))
            ->assertSessionHasErrors('default_currency');
        $this->assertSame('USD', $branch->refresh()->default_currency);
        $this->assertDatabaseCount('notifications', 0);
    }

    public static function financialDependencies(): array
    {
        return [['cash'], ['source_transfer'], ['destination_transfer'], ['source_fee'], ['destination_fee']];
    }

    #[DataProvider('customerDependencies')]
    public function test_clients_with_beneficiaries_or_transfers_cannot_be_deleted(string $dependency): void
    {
        $customer = Customer::factory()->create();
        if ($dependency === 'beneficiary') {
            Beneficiary::factory()->create(['customer_id' => $customer->id, 'branch_id' => $customer->branch_id]);
        } else {
            $recipient = Beneficiary::factory()->create(['branch_id' => $customer->branch_id, 'customer_id' => null]);
            Transfer::factory()->create(['sender_id' => $customer->id, 'beneficiary_id' => $recipient->id, 'source_branch_id' => $customer->branch_id]);
        }
        $this->actingAs($this->administrator())->followingRedirects()->delete(route('customers.destroy', $customer), ['confirmation' => '1'])
            ->assertOk()->assertSee('Suppression impossible')->assertSee('conserver son historique');
        $this->assertModelExists($customer);
        $this->assertDatabaseCount('beneficiaries', 1);
        $this->assertDatabaseCount('transfers', $dependency === 'transfer' ? 1 : 0);
    }

    public static function customerDependencies(): array
    {
        return [['beneficiary'], ['transfer']];
    }

    #[DataProvider('restrictedRoles')]
    public function test_non_administrators_cannot_edit_or_delete_even_with_creation_permissions(Role $role): void
    {
        $customer = Customer::factory()->create();
        $branch = $customer->branch;
        $actor = User::factory()->for($branch)->create()->assignRole($role->value)
            ->givePermissionTo(['branches.manage', 'customers.create', 'customers.view']);
        $this->actingAs($actor);
        foreach (['customers' => $customer, 'branches' => $branch] as $resource => $record) {
            $this->assertFalse($actor->can('update', $record));
            $this->assertFalse($actor->can('delete', $record));
            $this->get(route($resource.'.index'))->assertDontSee(route($resource.'.edit', $record));
            $this->get(route($resource.'.edit', $record))->assertForbidden();
            $this->put(route($resource.'.update', $record), [])->assertForbidden();
            $this->delete(route($resource.'.destroy', $record), ['confirmation' => '1'])->assertForbidden();
            $this->assertModelExists($record);
        }
    }

    public static function restrictedRoles(): array
    {
        return [[Role::SourceCashier], [Role::DestinationCashier], [Role::Manager]];
    }

    public function test_guests_cannot_modify_records(): void
    {
        $customer = Customer::factory()->create();
        foreach (['customers' => $customer, 'branches' => $customer->branch] as $resource => $record) {
            $this->get(route($resource.'.edit', $record))->assertRedirect(route('login'));
            $this->put(route($resource.'.update', $record), [])->assertRedirect(route('login'));
            $this->delete(route($resource.'.destroy', $record), ['confirmation' => '1'])->assertRedirect(route('login'));
            $this->assertModelExists($record);
        }
    }

    public function test_duplicate_agency_code_and_invalid_status_are_rejected_without_writes(): void
    {
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();
        $original = $branch->refresh()->getAttributes();
        $this->actingAs($this->administrator())->put(route('branches.update', $branch), $this->branchInput($branch, ['code' => $other->code, 'status' => 'INVALID']))
            ->assertSessionHasErrors(['code', 'status']);
        $this->assertSame($original, $branch->refresh()->getAttributes());
    }

    public function test_invalid_customer_fields_show_errors_and_preserve_input(): void
    {
        $customer = Customer::factory()->create();
        $original = $customer->refresh()->getAttributes();
        $this->actingAs($this->administrator())->followingRedirects()->from(route('customers.edit', $customer))
            ->put(route('customers.update', $customer), $this->customerInput($customer, ['phone' => 'INVALID', 'first_name' => 'Retained', 'status' => 'INVALID']))
            ->assertOk()->assertSee('Retained')->assertSee('phone-error')->assertSee('status-error');
        $this->assertSame($original, $customer->refresh()->getAttributes());
    }

    public function test_disabled_branch_blocks_cashier_access_and_can_be_reactivated(): void
    {
        $admin = $this->administrator();
        $branch = Branch::factory()->create();
        $cashier = User::factory()->for($branch)->create()->assignRole(Role::SourceCashier->value);
        $this->actingAs($admin)->put(route('branches.update', $branch), $this->branchInput($branch, ['status' => 'INACTIVE']))->assertSessionHasNoErrors();
        $this->actingAs($cashier)->get(route('dashboard'))->assertForbidden();
        $this->actingAs($admin)->put(route('branches.update', $branch), $this->branchInput($branch, ['status' => 'ACTIVE']))->assertSessionHasNoErrors();
        $this->actingAs($cashier->fresh())->get(route('dashboard'))->assertOk();
    }

    private function administrator(): User
    {
        return User::factory()->create()->assignRole(Role::Administrator->value);
    }

    private function branchInput(Branch $branch, array $changes = []): array
    {
        return [...$branch->only(['name', 'code', 'country', 'city', 'default_currency', 'timezone']), 'status' => 'ACTIVE', ...$changes];
    }

    private function customerInput(Customer $customer, array $changes = []): array
    {
        return [...$customer->only(['first_name', 'last_name', 'phone', 'email', 'country', 'city', 'address', 'id_type', 'id_number']), 'status' => 'ACTIVE', ...$changes];
    }

    private function addBranchDependency(Branch $branch, string $dependency): void
    {
        match ($dependency) {
            'user' => User::factory()->create(['branch_id' => $branch->id]),
            'customer' => Customer::factory()->create(['branch_id' => $branch->id]),
            'beneficiary' => Beneficiary::factory()->create(['branch_id' => $branch->id]),
            'cash' => CashAccount::factory()->create(['branch_id' => $branch->id]),
            'source_transfer' => Transfer::factory()->create(['source_branch_id' => $branch->id]),
            'destination_transfer' => Transfer::factory()->create(['destination_branch_id' => $branch->id]),
            'source_fee' => TransferFeeRule::factory()->create(['source_branch_id' => $branch->id]),
            'destination_fee' => TransferFeeRule::factory()->create(['destination_branch_id' => $branch->id]),
        };
    }
}
