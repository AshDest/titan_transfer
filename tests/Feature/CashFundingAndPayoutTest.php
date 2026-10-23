<?php

namespace Tests\Feature;

use App\Enums\CashMovementType;
use App\Enums\RecordStatus;
use App\Enums\Role;
use App\Enums\TransferStatus;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\ExchangeRate;
use App\Models\InboxNotification;
use App\Models\Transfer;
use App\Models\TransferEvent;
use App\Models\TransferFeeRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CashFundingAndPayoutTest extends TestCase
{
    use RefreshDatabase;

    private Branch $dubai;

    private User $cashier;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 12:00:00', 'UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->dubai = Branch::factory()->create(['name' => 'Titan Dubai', 'default_currency' => 'AED', 'timezone' => 'Asia/Dubai']);
        $this->cashier = User::factory()->for($this->dubai)->create()->assignRole(Role::DestinationCashier->value);
        $this->admin = User::factory()->create()->assignRole(Role::Administrator->value);
    }

    public function test_source_deposit_dubai_funding_and_beneficiary_payment_use_separate_cash_accounts(): void
    {
        $source = Branch::factory()->create(['default_currency' => 'USD']);
        $senderCashier = User::factory()->for($source)->create()->assignRole(Role::SourceCashier->value);
        ExchangeRate::factory()->create();
        TransferFeeRule::factory()->create();
        $input = ['source_branch_id' => $source->id, 'destination_branch_id' => $this->dubai->id, 'amount_received' => '100',
            'sender_mode' => 'new', 'beneficiary_mode' => 'new',
            'sender' => ['first_name' => 'Marie', 'phone' => '+243970000001', 'country' => 'RDC'],
            'beneficiary' => ['first_name' => 'Paul', 'phone' => '+971500000001', 'country' => 'UAE']];
        $preview = $this->actingAs($senderCashier)->post(route('transfers.preview'), $input)->assertOk();
        $this->post(route('transfers.store'), ['review_token' => $preview->viewData('token'), 'cash_received' => '1'])->assertSessionHasNoErrors();
        $transfer = Transfer::sole();
        $sourceCash = CashAccount::sole();
        $this->assertSame('100.00', $sourceCash->balance);
        $this->assertFalse(CashAccount::where('branch_id', $this->dubai->id)->exists());
        $this->actingAs($this->admin)->post(route('transfers.approve', $transfer), ['confirmed' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($this->cashier)->post(route('transfers.pay', $transfer), ['confirmed' => '1'])->assertSessionHasErrors('payment');
        $this->assertSame(TransferStatus::Approved, $transfer->refresh()->status);
        $this->assertFalse(CashAccount::where('branch_id', $this->dubai->id)->exists());

        $this->get(route('treasury.index'))->assertOk()->assertSee('Approvisionner')->assertSee('0,00 AED');
        $this->get(route('treasury.cash-ins.create'))->assertOk()->assertSee('Personne qui apporte l’argent');
        $funding = $this->funding();
        $this->post(route('treasury.cash-ins.store'), $funding)->assertSessionHasNoErrors();
        $this->post(route('treasury.cash-ins.store'), $funding)->assertSessionHasNoErrors();
        $this->assertSame(1, CashMovement::where('type', CashMovementType::CashIn)->count());
        $this->assertSame('1000.00', $this->dubaiCash()->balance);
        $this->get(route('transfers.show', $transfer))->assertOk()->assertSee('Confirmer le paiement');
        $this->post(route('transfers.pay', $transfer), ['confirmed' => '1', 'amount' => '1', 'currency' => 'USD', 'paid_by' => $senderCashier->id])
            ->assertSessionHasNoErrors()->assertRedirect(route('transfers.show', $transfer));
        $transfer->refresh();
        $this->assertSame(TransferStatus::Paid, $transfer->status);
        $this->assertSame($this->cashier->id, $transfer->paid_by);
        $this->assertNotNull($transfer->paid_at);
        $this->assertSame('644.01', $this->dubaiCash()->balance);
        $this->assertSame('100.00', $sourceCash->refresh()->balance);
        $payout = CashMovement::where('type', CashMovementType::Payout)->sole();
        $this->assertSame('355.99', $payout->amount);
        $this->assertSame('AED', $payout->currency);
        $this->assertSame('Paul', $payout->recipient_name);
        $this->assertSame('1000.00', $payout->balance_before);
        $this->assertSame('644.01', $payout->balance_after);
        $this->assertSame($this->dubaiCash()->id, $payout->cash_account_id);
        $this->assertSame(1, TransferEvent::where('action', 'TRANSFER_PAID')->count());
        $this->post(route('transfers.pay', $transfer), ['confirmed' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('644.01', $this->dubaiCash()->balance);
        $this->assertSame(1, CashMovement::where('type', CashMovementType::Payout)->count());
        $this->get(route('transfers.show', $transfer))->assertOk()->assertSee('REÇU DE PAIEMENT')->assertSee('Montant remis')->assertDontSee('Confirmer le paiement');
        $this->get(route('treasury.index', ['type' => 'CASH_IN', 'q' => 'Apporteur', 'date_from' => '2026-10-23', 'date_to' => '2026-10-23']))
            ->assertViewHas('records', fn ($records): bool => $records->total() === 1)->assertSee('Apporteur Test');
        $this->get(route('treasury.index', ['type' => 'PAYOUT']))->assertViewHas('records', fn ($records): bool => $records->total() === 1)->assertSee('Paul');
        $this->assertTrue($this->cashier->notifications()->get()->contains(fn ($notification): bool => $notification->data['title'] === 'Caisse approvisionnée'));
        $this->assertTrue($senderCashier->notifications()->get()->contains(fn ($notification): bool => $notification->data['event'] === 'paid'));
    }

    #[DataProvider('invalidFunding')]
    public function test_invalid_funding_creates_no_account_or_movement(array $changes, string $field): void
    {
        $this->actingAs($this->cashier)->post(route('treasury.cash-ins.store'), $this->funding($changes))->assertSessionHasErrors($field);
        $this->assertDatabaseCount('cash_accounts', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public static function invalidFunding(): array
    {
        return [
            [['amount' => '0'], 'amount'], [['amount' => '-1'], 'amount'], [['amount' => '10.001'], 'amount'],
            [['amount' => 10.5], 'amount'], [['amount' => '10000000000000000'], 'amount'],
            [['provider_name' => '  '], 'provider_name'], [['description' => ' '], 'description'],
            [['currency' => 'USD'], 'currency'], [['confirmed' => '0'], 'confirmed'],
            [['occurred_on' => '2026-10-24'], 'occurred_on'], [['submission_key' => 'invalid'], 'submission_key'],
        ];
    }

    public function test_funding_cannot_be_duplicated_with_different_details_or_overflow_the_balance(): void
    {
        $this->actingAs($this->cashier);
        $data = $this->funding(['amount' => '9999999999999999.98']);
        $this->post(route('treasury.cash-ins.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('treasury.cash-ins.store'), [...$data, 'provider_name' => 'Another person'])->assertSessionHasErrors('submission_key');
        $this->post(route('treasury.cash-ins.store'), $this->funding(['amount' => '0.02']))->assertSessionHasErrors('amount');
        $this->assertSame('9999999999999999.98', $this->dubaiCash()->balance);
        $this->post(route('treasury.cash-ins.store'), $this->funding(['amount' => '0.01']))->assertSessionHasNoErrors();
        $this->assertSame('9999999999999999.99', $this->dubaiCash()->balance);
    }

    public function test_destination_cashier_cannot_fund_or_pay_at_another_branch_even_with_global_permission(): void
    {
        $foreign = Branch::factory()->create(['default_currency' => 'AED']);
        $transfer = Transfer::factory()->create(['destination_branch_id' => $foreign->id, 'status' => TransferStatus::Approved]);
        $this->cashier->givePermissionTo('branches.view-all');
        $this->actingAs($this->cashier)->get(route('treasury.cash-ins.create'))->assertDontSee($foreign->name);
        $this->post(route('treasury.cash-ins.store'), $this->funding(['branch_id' => $foreign->id]))->assertForbidden();
        $this->post(route('transfers.pay', $transfer), ['confirmed' => '1'])->assertForbidden();
        $this->assertDatabaseCount('cash_accounts', 0);
    }

    #[DataProvider('restrictedRoles')]
    public function test_source_cashiers_and_managers_cannot_fund_or_pay_even_with_added_permissions(Role $role): void
    {
        $user = User::factory()->for($this->dubai)->create()->assignRole($role->value);
        $user->givePermissionTo(['cash.deposit', 'transfers.pay']);
        $transfer = $this->approvedTransfer();
        $this->actingAs($user)->get(route('treasury.cash-ins.create'))->assertForbidden();
        $this->post(route('treasury.cash-ins.store'), $this->funding())->assertForbidden();
        $this->post(route('transfers.pay', $transfer), ['confirmed' => '1'])->assertForbidden();
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public static function restrictedRoles(): array
    {
        return [[Role::SourceCashier], [Role::Manager]];
    }

    public function test_insufficient_balance_inactive_account_and_missing_confirmation_block_payment(): void
    {
        $transfer = $this->approvedTransfer();
        $this->actingAs($this->cashier)->post(route('treasury.cash-ins.store'), $this->funding(['amount' => '100']))->assertSessionHasNoErrors();
        $this->post(route('transfers.pay', $transfer), ['confirmed' => '1'])->assertSessionHasErrors('payment');
        $this->post(route('transfers.pay', $transfer))->assertSessionHasErrors('confirmed');
        $this->dubaiCash()->forceFill(['status' => RecordStatus::Inactive, 'balance' => '1000.00'])->save();
        $this->post(route('transfers.pay', $transfer), ['confirmed' => '1'])->assertSessionHasErrors('payment');
        $this->post(route('treasury.cash-ins.store'), $this->funding())->assertSessionHasErrors('branch_id');
        $this->assertSame(TransferStatus::Approved, $transfer->refresh()->status);
        $this->assertSame('1000.00', $this->dubaiCash()->balance);
        $this->assertSame(0, CashMovement::where('type', CashMovementType::Payout)->count());
    }

    #[DataProvider('unpayableStatuses')]
    public function test_unvalidated_rejected_cancelled_and_reversed_transfers_cannot_be_paid(TransferStatus $status): void
    {
        $transfer = $this->approvedTransfer(['status' => $status]);
        $this->actingAs($this->admin)->post(route('transfers.pay', $transfer), ['confirmed' => '1'])->assertSessionHasErrors('payment');
        $this->assertSame($status, $transfer->refresh()->status);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public static function unpayableStatuses(): array
    {
        return [[TransferStatus::Draft], [TransferStatus::Pending], [TransferStatus::Rejected], [TransferStatus::Cancelled], [TransferStatus::Reversed]];
    }

    public function test_admin_can_fund_and_pay_ready_transfers_and_empty_the_cash_account_exactly(): void
    {
        $transfer = $this->approvedTransfer(['status' => TransferStatus::ReadyForPayout]);
        $this->actingAs($this->admin)->post(route('treasury.cash-ins.store'), $this->funding(['amount' => '355.99']))->assertSessionHasNoErrors();
        $this->post(route('transfers.pay', $transfer), ['confirmed' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('0.00', $this->dubaiCash()->balance);
        $this->assertSame($this->admin->id, $transfer->refresh()->paid_by);
    }

    public function test_inactive_branch_blocks_funding_and_payment(): void
    {
        $transfer = $this->approvedTransfer();
        $this->dubai->update(['status' => RecordStatus::Inactive]);
        $this->actingAs($this->admin)->post(route('treasury.cash-ins.store'), $this->funding())->assertForbidden();
        $this->post(route('transfers.pay', $transfer), ['confirmed' => '1'])->assertForbidden();
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_notification_failure_rolls_back_funding(): void
    {
        Event::listen('eloquent.creating: '.InboxNotification::class, fn () => throw new RuntimeException('Simulated notification failure'));
        $this->withoutExceptionHandling()->actingAs($this->cashier);
        try {
            $this->post(route('treasury.cash-ins.store'), $this->funding());
            $this->fail('Expected transaction failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated notification failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('cash_accounts', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_notification_failure_rolls_back_payment_and_balance(): void
    {
        $transfer = $this->approvedTransfer();
        $this->actingAs($this->cashier)->post(route('treasury.cash-ins.store'), $this->funding())->assertSessionHasNoErrors();
        Event::listen('eloquent.creating: '.InboxNotification::class, fn () => throw new RuntimeException('Simulated notification failure'));
        $this->withoutExceptionHandling();
        try {
            $this->post(route('transfers.pay', $transfer), ['confirmed' => '1']);
            $this->fail('Expected transaction failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated notification failure', $exception->getMessage());
        }
        $this->assertSame('1000.00', $this->dubaiCash()->balance);
        $this->assertSame(TransferStatus::Approved, $transfer->refresh()->status);
        $this->assertNull($transfer->paid_at);
        $this->assertSame(0, CashMovement::where('type', CashMovementType::Payout)->count());
        $this->assertDatabaseCount('transfer_events', 0);
    }

    public function test_migration_grants_funding_permission_to_existing_admins_and_destination_cashiers(): void
    {
        $migration = require database_path('migrations/2026_10_23_095607_add_cash_funding_and_payout_details.php');
        $migration->down();
        $migration->up();
        $this->assertTrue($this->admin->fresh()->can('cash.deposit'));
        $this->assertTrue($this->cashier->fresh()->can('cash.deposit'));
        $source = User::factory()->for($this->dubai)->create()->assignRole(Role::SourceCashier->value);
        $this->assertFalse($source->can('cash.deposit'));
    }

    private function dubaiCash(): CashAccount
    {
        return CashAccount::where('branch_id', $this->dubai->id)->where('currency', 'AED')->sole();
    }

    /** @param array<string, mixed> $changes */
    private function approvedTransfer(array $changes = []): Transfer
    {
        return Transfer::factory()->create(['destination_branch_id' => $this->dubai->id, 'status' => TransferStatus::Approved, ...$changes]);
    }

    /** @return array<string, mixed> */
    private function funding(array $changes = []): array
    {
        return ['branch_id' => $this->dubai->id, 'currency' => 'AED', 'amount' => '1000.00', 'provider_name' => 'Apporteur Test',
            'description' => 'Fonds pour les retraits', 'occurred_on' => '2026-10-23', 'submission_key' => (string) Str::uuid(), 'confirmed' => '1', ...$changes];
    }
}
