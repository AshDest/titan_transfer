<?php

namespace Tests\Feature;

use App\Actions\Transfers\CreateTransfer;
use App\Enums\RecordStatus;
use App\Enums\Role;
use App\Enums\TransferStatus;
use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Transfer;
use App\Models\TransferEvent;
use App\Models\TransferFeeRule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TransferInitiationTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Branch $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $source = Branch::factory()->create(['default_currency' => 'USD']);
        $this->cashier = User::factory()->for($source)->create()->assignRole(Role::SourceCashier->value);
        $this->destination = Branch::factory()->create(['default_currency' => 'AED']);
        ExchangeRate::factory()->create();
        TransferFeeRule::factory()->create();
    }

    public function test_operation_is_accessible_from_dashboard_menu_and_list(): void
    {
        $this->actingAs($this->cashier)->get(route('dashboard'))->assertOk()->assertSee('Nouvelle opération')->assertSee(route('transfers.create'));
        $this->get(route('transfers.create'))->assertOk()->assertSee('Nouveau client')->assertSee('Nouveau bénéficiaire')->assertSee('Vérifier l’opération');
        $this->get(route('transfers.index'))->assertOk()->assertSee('Nouvelle opération');
    }

    public function test_preview_creates_nothing_and_confirmation_atomically_saves_parties_transfer_deposit_and_audit(): void
    {
        $token = $this->previewToken();
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('transfers', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $response = $this->post(route('transfers.store'), ['review_token' => $token, 'cash_received' => '1', 'payout_amount' => '999999', 'status' => 'PAID']);
        $transfer = Transfer::sole();
        $response->assertRedirect(route('transfers.show', $transfer))->assertSessionHasNoErrors();
        $this->assertSame(TransferStatus::Pending, $transfer->status);
        $this->assertSame('355.99', $transfer->payout_amount);
        $this->assertSame('100.00', CashAccount::sole()->balance);
        $this->assertSame('0.00', CashMovement::sole()->balance_before);
        $this->assertSame('100.00', CashMovement::sole()->balance_after);
        $this->assertSame($this->cashier->id, $transfer->created_by);
        $this->assertSame(Customer::sole()->id, Beneficiary::sole()->customer_id);
        $this->assertDatabaseHas('transfer_events', ['transfer_id' => $transfer->id, 'user_id' => $this->cashier->id, 'action' => 'TRANSFER_CREATED']);
        $this->get(route('transfers.show', $transfer))->assertOk()->assertSee('355.99 AED')->assertSee('Imprimer le reçu');
        $this->get(route('transfers.index'))->assertSee($transfer->reference);
    }

    public function test_repeated_confirmation_is_idempotent(): void
    {
        $token = $this->previewToken();
        for ($i = 0; $i < 2; $i++) {
            $this->post(route('transfers.store'), ['review_token' => $token, 'cash_received' => '1'])->assertSessionHasNoErrors();
        }
        foreach (['customers', 'beneficiaries', 'transfers', 'cash_movements', 'transfer_events'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertSame('100.00', CashAccount::sole()->balance);
    }

    public function test_cash_confirmation_is_required_and_input_is_preserved(): void
    {
        $token = $this->previewToken();
        $this->post(route('transfers.store'), ['review_token' => $token])->assertRedirect(route('transfers.create'))->assertSessionHasErrors('cash_received')->assertSessionHasInput('sender.first_name', 'Marie');
        $this->assertDatabaseCount('transfers', 0);
    }

    public function test_modify_returns_to_prefilled_form_without_saving(): void
    {
        $token = $this->previewToken();
        $this->followingRedirects()->post(route('transfers.revise'), ['review_token' => $token])->assertOk()->assertSee('Marie')->assertSee('100.00');
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_existing_people_are_reused_and_foreign_people_are_refused(): void
    {
        $sender = Customer::factory()->create(['branch_id' => $this->cashier->branch_id]);
        $beneficiary = Beneficiary::factory()->create(['branch_id' => $this->cashier->branch_id, 'customer_id' => $sender->id]);
        $data = [...$this->input(), 'sender_mode' => 'existing', 'sender_id' => $sender->id, 'beneficiary_mode' => 'existing', 'beneficiary_id' => $beneficiary->id];
        $token = $this->previewToken($data);
        $this->post(route('transfers.store'), ['review_token' => $token, 'cash_received' => '1'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('beneficiaries', 1);
        $foreign = Customer::factory()->create();
        $this->post(route('transfers.preview'), [...$data, 'sender_id' => $foreign->id])->assertSessionHasErrors('sender_id');
        $other = Customer::factory()->create(['branch_id' => $this->cashier->branch_id]);
        $this->post(route('transfers.preview'), [...$data, 'sender_id' => $other->id])->assertSessionHasErrors('beneficiary_id');
    }

    public function test_people_search_is_scoped_and_does_not_expose_identity_fields(): void
    {
        Customer::factory()->create(['branch_id' => $this->cashier->branch_id, 'first_name' => 'Visible', 'id_number' => 'Secret-ID']);
        Customer::factory()->create(['first_name' => 'Hidden']);
        $this->actingAs($this->cashier)->getJson(route('transfers.people', ['branch_id' => $this->cashier->branch_id, 'kind' => 'sender']))
            ->assertOk()->assertJsonCount(1)->assertSee('Visible')->assertDontSee('Hidden')->assertDontSee('Secret-ID');
        $this->getJson(route('transfers.people', ['branch_id' => $this->destination->id, 'kind' => 'sender']))->assertForbidden();
    }

    public function test_destination_cashier_cannot_initiate_or_view_pending_source_transfer(): void
    {
        $transfer = $this->createOperation();
        $destinationCashier = User::factory()->for($this->destination)->create()->assignRole(Role::DestinationCashier->value);
        $this->actingAs($destinationCashier)->get(route('transfers.create'))->assertForbidden();
        $this->post(route('transfers.preview'), $this->input())->assertForbidden();
        $this->get(route('transfers.index'))->assertOk()->assertDontSee($transfer->reference);
        $this->get(route('transfers.show', $transfer))->assertForbidden();
    }

    public function test_another_source_agency_cannot_see_or_submit_this_agencys_transfer(): void
    {
        $transfer = $this->createOperation();
        $other = User::factory()->for(Branch::factory())->create()->assignRole(Role::SourceCashier->value);
        $this->actingAs($other)->get(route('transfers.show', $transfer))->assertForbidden();
        $this->get(route('transfers.index'))->assertDontSee($transfer->reference);
        $this->post(route('transfers.preview'), $this->input())->assertForbidden();
    }

    public function test_new_customer_requires_customer_creation_permission(): void
    {
        $user = User::factory()->for($this->cashier->branch)->create()->givePermissionTo(['dashboard.view', 'transfers.create', 'transfers.view']);
        $this->actingAs($user)->post(route('transfers.preview'), $this->input())->assertForbidden();
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_rate_change_between_review_and_confirmation_requires_new_review(): void
    {
        $token = $this->previewToken();
        ExchangeRate::factory()->create(['rate' => '4.000000']);
        $this->post(route('transfers.store'), ['review_token' => $token, 'cash_received' => '1'])->assertRedirect(route('transfers.create'))->assertSessionHasErrors('review_token');
        $this->assertDatabaseCount('transfers', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_snapshot_does_not_change_when_new_rate_and_fee_versions_are_added(): void
    {
        $transfer = $this->createOperation();
        ExchangeRate::factory()->create(['rate' => '4.000000']);
        TransferFeeRule::factory()->create(['percentage' => '5.0000']);
        $this->assertSame('3.670000', $transfer->refresh()->exchange_rate);
        $this->assertSame('355.99', $transfer->payout_amount);
        $this->assertSame('3.0000', $transfer->fee_percentage);
    }

    public function test_inactive_destination_or_cash_account_prevents_commit(): void
    {
        $token = $this->previewToken();
        CashAccount::factory()->create(['branch_id' => $this->cashier->branch_id, 'status' => RecordStatus::Inactive]);
        $this->post(route('transfers.store'), ['review_token' => $token, 'cash_received' => '1'])->assertSessionHasErrors('review_token');
        $this->assertDatabaseCount('transfers', 0);
        $this->destination->update(['status' => RecordStatus::Inactive]);
        $this->post(route('transfers.preview'), $this->input())->assertSessionHasErrors('destination_branch_id');
    }

    public function test_failure_writing_audit_rolls_back_entire_operation(): void
    {
        $action = app(CreateTransfer::class);
        $review = $action->review($this->cashier, $this->input());
        Event::listen('eloquent.creating: '.TransferEvent::class, fn () => throw new \RuntimeException('Audit unavailable'));
        try {
            $action->handle($this->cashier, $this->input(), $review['fingerprint'], (string) Str::uuid(), '127.0.0.1');
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.TransferEvent::class);
        }
        foreach (['customers', 'beneficiaries', 'transfers', 'cash_accounts', 'cash_movements', 'transfer_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_same_currency_transfer_preserves_large_decimal_values_in_storage(): void
    {
        $this->destination->update(['default_currency' => 'USD']);
        TransferFeeRule::factory()->create(['percentage' => '0.0000']);
        $data = [...$this->input(), 'amount_received' => '9999999999999999.99'];
        $token = $this->previewToken($data);
        $this->post(route('transfers.store'), ['review_token' => $token, 'cash_received' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('9999999999999999.99', Transfer::sole()->payout_amount);
        $this->assertSame('9999999999999999.99', CashAccount::sole()->balance);
        $this->assertNull(Transfer::sole()->exchange_rate_id);
    }

    public function test_additional_operations_accumulate_in_one_cash_account(): void
    {
        $this->createOperation();
        $this->createOperation();
        $this->assertDatabaseCount('cash_accounts', 1);
        $this->assertDatabaseCount('cash_movements', 2);
        $this->assertSame('200.00', CashAccount::sole()->balance);
        $this->assertSame('100.00', CashMovement::latest('id')->first()->balance_before);
    }

    public function test_expired_tampered_and_another_users_review_cannot_be_confirmed(): void
    {
        $token = $this->previewToken();
        $this->post(route('transfers.store'), ['review_token' => 'tampered', 'cash_received' => '1'])->assertSessionHasErrors('review_token');
        $other = User::factory()->for($this->cashier->branch)->create()->assignRole(Role::SourceCashier->value);
        $this->actingAs($other)->post(route('transfers.store'), ['review_token' => $token, 'cash_received' => '1'])->assertForbidden();
        $this->travel(11)->minutes();
        $this->actingAs($this->cashier)->post(route('transfers.store'), ['review_token' => $token, 'cash_received' => '1'])->assertRedirect(route('transfers.create'))->assertSessionHasErrors('review_token')->assertSessionHasInput('sender.first_name', 'Marie');
        $this->assertDatabaseCount('transfers', 0);
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amounts_are_rejected(mixed $amount): void
    {
        $this->actingAs($this->cashier)->post(route('transfers.preview'), [...$this->input(), 'amount_received' => $amount])->assertSessionHasErrors('amount_received');
        $this->assertDatabaseCount('transfers', 0);
    }

    public static function invalidAmounts(): array
    {
        return [['0'], ['-10'], ['1e3'], ['1.001'], ['10000000000000000'], [['100']], ['0.001']];
    }

    public function test_disabled_latest_rate_is_not_replaced_by_an_older_active_rate(): void
    {
        ExchangeRate::factory()->create(['active' => false]);
        $this->actingAs($this->cashier)->post(route('transfers.preview'), $this->input())->assertSessionHasErrors('destination_branch_id');
    }

    public function test_specific_fee_overrides_global_rule_and_overlapping_ranges_are_refused(): void
    {
        TransferFeeRule::factory()->create(['source_branch_id' => $this->cashier->branch_id, 'destination_branch_id' => $this->destination->id, 'percentage' => '2.0000']);
        $review = app(CreateTransfer::class)->review($this->cashier, $this->input());
        $this->assertSame('2.00', $review['quote']->feeAmount);
        TransferFeeRule::factory()->create(['source_branch_id' => $this->cashier->branch_id, 'destination_branch_id' => $this->destination->id, 'minimum_amount' => '50.00', 'percentage' => '1.0000']);
        $this->actingAs($this->cashier)->post(route('transfers.preview'), $this->input())->assertSessionHasErrors('amount_received');
    }

    public function test_committed_transfer_cannot_be_edited_in_place(): void
    {
        $transfer = $this->createOperation();
        $this->expectException(\LogicException::class);
        $transfer->forceFill(['payout_amount' => '999.00'])->save();
    }

    public function test_validation_errors_and_party_inputs_are_visible_on_form(): void
    {
        $data = $this->input();
        unset($data['sender']['last_name']);
        $this->actingAs($this->cashier)->followingRedirects()->from(route('transfers.create'))->post(route('transfers.preview'), $data)
            ->assertOk()->assertSee('Marie')->assertSee('sender.last_name-error');
    }

    public function test_unauthenticated_visitors_cannot_open_or_create_transfers(): void
    {
        $this->get(route('transfers.create'))->assertRedirect(route('login'));
        $this->post(route('transfers.preview'), $this->input())->assertRedirect(route('login'));
        $this->post(route('transfers.store'), [])->assertRedirect(route('login'));
    }

    private function input(): array
    {
        return ['source_branch_id' => $this->cashier->branch_id, 'destination_branch_id' => $this->destination->id, 'amount_received' => '100.00',
            'sender_mode' => 'new', 'sender' => ['first_name' => 'Marie', 'last_name' => 'Test', 'phone' => '+243970000001', 'country' => 'RDC'],
            'beneficiary_mode' => 'new', 'beneficiary' => ['first_name' => 'Paul', 'last_name' => 'Test', 'phone' => '+971500000001', 'country' => 'Émirats arabes unis']];
    }

    private function previewToken(?array $data = null): string
    {
        return $this->actingAs($this->cashier)->post(route('transfers.preview'), $data ?? $this->input())->assertOk()->assertSee('Confirmer l’opération')->viewData('token');
    }

    private function createOperation(): Transfer
    {
        $token = $this->previewToken();
        $this->post(route('transfers.store'), ['review_token' => $token, 'cash_received' => '1'])->assertSessionHasNoErrors();

        return Transfer::latest('id')->firstOrFail();
    }
}
