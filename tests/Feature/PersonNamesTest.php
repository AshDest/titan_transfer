<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Transfer;
use App\Models\TransferFeeRule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PersonNamesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->branch = Branch::factory()->create(['country' => 'RDC']);
        $this->admin = User::factory()->create()->assignRole(Role::Administrator->value);
    }

    public function test_customer_and_beneficiary_forms_have_one_name_field_and_store_names_without_splitting(): void
    {
        $this->actingAs($this->admin);
        foreach (['customers', 'beneficiaries'] as $module) {
            $this->get(route($module.'.create'))->assertOk()->assertSee('Noms')
                ->assertSee('name="full_name"', false)->assertDontSee('name="first_name"', false)->assertDontSee('name="last_name"', false);
            $this->post(route($module.'.store'), $this->input())->assertSessionHasNoErrors();
        }
        foreach ([Customer::sole(), Beneficiary::sole()] as $person) {
            $this->assertSame('Jean Pierre Kabeya', $person->first_name);
            $this->assertNull($person->last_name);
        }
        $this->get(route('customers.index', ['q' => 'Jean Pierre Kabeya']))->assertViewHas('records', fn ($records): bool => $records->total() === 1);
        $this->get(route('beneficiaries.index', ['q' => 'Jean Pierre Kabeya']))->assertViewHas('records', fn ($records): bool => $records->total() === 1);
    }

    public function test_existing_names_are_combined_on_edit_and_old_receipts_remain_unchanged(): void
    {
        $transfer = Transfer::factory()->create();
        $customer = Customer::findOrFail($transfer->sender_id);
        $customer->update(['first_name' => 'Jean Pierre', 'last_name' => 'Kabeya']);
        $snapshot = $transfer->parties_snapshot;
        $this->actingAs($this->admin)->get(route('customers.edit', $customer))->assertOk()->assertSee('value="Jean Pierre Kabeya"', false)
            ->assertDontSee('name="first_name"', false)->assertDontSee('name="last_name"', false);
        $this->put(route('customers.update', $customer), [...$this->input(), 'status' => 'ACTIVE'])->assertSessionHasNoErrors();
        $this->assertSame('Jean Pierre Kabeya', $customer->refresh()->first_name);
        $this->assertNull($customer->last_name);
        $this->assertSame($snapshot, $transfer->refresh()->parties_snapshot);
        $this->get(route('customers.edit', $customer))->assertSee('value="Jean Pierre Kabeya"', false)->assertDontSee('Kabeya Kabeya');
    }

    public function test_transfer_preview_revision_confirmation_and_receipt_keep_complete_names_and_automatic_countries(): void
    {
        $destination = Branch::factory()->create(['default_currency' => 'AED', 'country' => 'Émirats arabes unis']);
        ExchangeRate::factory()->create();
        TransferFeeRule::factory()->create();
        $input = ['source_branch_id' => $this->branch->id, 'destination_branch_id' => $destination->id, 'amount_received' => '100',
            'sender_mode' => 'new', 'beneficiary_mode' => 'new',
            'sender' => ['full_name' => 'Jean Pierre Kabeya', 'phone' => '+243970000001'],
            'beneficiary' => ['full_name' => 'Marie Louise Dupont', 'phone' => '+971500000001']];
        $this->actingAs($this->admin)->get(route('transfers.create'))->assertSee('name="sender[full_name]"', false)
            ->assertSee('name="beneficiary[full_name]"', false)->assertDontSee('name="sender[first_name]"', false)->assertDontSee('name="beneficiary[last_name]"', false);
        $preview = $this->post(route('transfers.preview'), $input)->assertOk()->assertSee('Jean Pierre Kabeya')->assertSee('Marie Louise Dupont');
        $this->assertSame('Jean Pierre Kabeya', $preview->viewData('snapshot')['sender']['first_name']);
        $this->assertSame('Émirats arabes unis', $preview->viewData('snapshot')['beneficiary']['country']);
        $this->post(route('transfers.revise'), ['review_token' => $preview->viewData('token')])->assertSessionHasInput('sender.full_name', 'Jean Pierre Kabeya');
        $this->get(route('transfers.create'))->assertSee('value="Jean Pierre Kabeya"', false);
        $this->post(route('transfers.store'), ['review_token' => $preview->viewData('token'), 'cash_received' => '1'])->assertSessionHasNoErrors();
        $transfer = Transfer::sole();
        $this->assertSame('Jean Pierre Kabeya', Customer::sole()->first_name);
        $this->assertSame('Marie Louise Dupont', Beneficiary::sole()->first_name);
        $this->assertNull($transfer->parties_snapshot['beneficiary']['last_name']);
        $this->get(route('transfers.show', $transfer))->assertOk()->assertSee('Jean Pierre Kabeya')->assertSee('Marie Louise Dupont')->assertSee('data-thermal-receipt', false);
        $this->get(route('transfers.people', ['branch_id' => $this->branch->id, 'kind' => 'sender', 'q' => 'Jean Pierre Kabeya']))->assertJsonCount(1);
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_full_names_are_rejected_without_falling_back_to_legacy_fields(mixed $name): void
    {
        $this->actingAs($this->admin)->post(route('customers.store'), $this->input(['full_name' => $name, 'first_name' => 'Hidden', 'last_name' => 'Hidden']))
            ->assertSessionHasErrors('full_name');
        $this->assertDatabaseCount('customers', 0);
    }

    public static function invalidNames(): array
    {
        return [[''], ['   '], [null], [['invalid']], [str_repeat('a', 256)]];
    }

    public function test_long_names_fit_storage_and_submitted_legacy_fields_do_not_duplicate_names(): void
    {
        $name = str_repeat('é', 255);
        $this->actingAs($this->admin)->post(route('customers.store'), $this->input(['full_name' => $name, 'first_name' => 'Ignored', 'last_name' => 'Ignored']))->assertSessionHasNoErrors();
        $this->assertSame($name, Customer::sole()->first_name);
        $this->assertNull(Customer::sole()->last_name);
    }

    /** @return array<string, mixed> */
    private function input(array $changes = []): array
    {
        return ['branch_id' => $this->branch->id, 'full_name' => 'Jean Pierre Kabeya', 'phone' => '+243970000001', 'country' => 'RDC', ...$changes];
    }
}
