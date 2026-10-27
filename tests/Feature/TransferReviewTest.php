<?php

namespace Tests\Feature;

use App\Actions\Transfers\CreateTransfer;
use App\Actions\Transfers\ReviewTransfer;
use App\Enums\Role;
use App\Enums\TransferStatus;
use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\InboxNotification;
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

class TransferReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $manager;

    private User $destinationCashier;

    private Transfer $transfer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $source = Branch::factory()->create(['default_currency' => 'USD']);
        $destination = Branch::factory()->create(['default_currency' => 'AED']);
        $this->cashier = User::factory()->for($source)->create()->assignRole(Role::SourceCashier->value);
        $this->manager = User::factory()->for($source)->create()->assignRole(Role::Manager->value);
        $this->destinationCashier = User::factory()->for($destination)->create()->assignRole(Role::DestinationCashier->value);
        ExchangeRate::factory()->create();
        TransferFeeRule::factory()->create();
        $sender = Customer::factory()->create(['branch_id' => $source->id]);
        $beneficiary = Beneficiary::factory()->create(['branch_id' => $source->id, 'customer_id' => $sender->id]);
        $input = ['source_branch_id' => $source->id, 'destination_branch_id' => $destination->id, 'amount_received' => '100.00',
            'sender_mode' => 'existing', 'sender_id' => $sender->id, 'beneficiary_mode' => 'existing', 'beneficiary_id' => $beneficiary->id];
        $action = app(CreateTransfer::class);
        $review = $action->review($this->cashier, $input);
        $this->transfer = $action->handle($this->cashier, $input, $review['fingerprint'], (string) Str::uuid(), null);
    }

    public function test_manager_approves_with_audit_notification_and_unchanged_financial_snapshot(): void
    {
        $original = $this->transfer->only(['amount_received', 'fee_percentage', 'fee_amount', 'net_amount', 'exchange_rate', 'payout_amount', 'exchange_rate_id', 'transfer_fee_rule_id', 'parties_snapshot']);
        $this->actingAs($this->destinationCashier)->get(route('transfers.show', $this->transfer))->assertOk()->assertDontSee('Confirmer le paiement');
        $this->actingAs($this->manager)->get(route('transfers.show', $this->transfer))->assertOk()->assertSee('Approuver le transfert')->assertSee('Rejeter le transfert');
        $this->post(route('transfers.approve', $this->transfer), ['confirmed' => '1', 'payout_amount' => '999', 'approved_by' => $this->cashier->id])->assertRedirect(route('transfers.show', $this->transfer))->assertSessionHasNoErrors();
        $transfer = $this->transfer->refresh();
        $this->assertSame(TransferStatus::Approved, $transfer->status);
        $this->assertSame($this->manager->id, $transfer->approved_by);
        $this->assertNotNull($transfer->approved_at);
        $this->assertSame($original, $transfer->only(array_keys($original)));
        $this->assertSame('100.00', CashAccount::sole()->balance);
        $this->assertDatabaseCount('cash_movements', 1);
        $event = TransferEvent::where('action', 'TRANSFER_APPROVED')->sole();
        $this->assertSame('PENDING', $event->old_values['status']);
        $this->assertSame('APPROVED', $event->new_values['status']);
        $this->assertSame($this->manager->id, $event->user_id);
        $this->assertSame('approved', $this->destinationCashier->notifications()->get()->first(fn ($notification) => $notification->data['event'] === 'approved')->data['event']);
        $this->actingAs($this->destinationCashier)->get(route('transfers.show', $transfer))->assertOk()->assertSee('Approuvé par')->assertDontSee('Approuver le transfert');
    }

    public function test_rejection_requires_reason_and_does_not_claim_to_refund_money(): void
    {
        $this->actingAs($this->manager)->post(route('transfers.reject', $this->transfer), [])->assertSessionHasErrors('rejection_reason');
        $this->post(route('transfers.reject', $this->transfer), ['rejection_reason' => '    '])->assertSessionHasErrors('rejection_reason');
        $this->post(route('transfers.reject', $this->transfer), ['rejection_reason' => 'Coordonnées du bénéficiaire incorrectes.'])->assertRedirect(route('transfers.show', $this->transfer));
        $this->assertSame(TransferStatus::Rejected, $this->transfer->refresh()->status);
        $this->assertSame($this->manager->id, $this->transfer->rejected_by);
        $this->assertSame('Coordonnées du bénéficiaire incorrectes.', $this->transfer->rejection_reason);
        $this->assertSame('100.00', CashAccount::sole()->balance);
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertDatabaseHas('transfer_events', ['action' => 'TRANSFER_REJECTED', 'user_id' => $this->manager->id]);
        $this->assertSame(2, $this->destinationCashier->notifications()->count());
        $this->get(route('transfers.show', $this->transfer))->assertSee('Coordonnées du bénéficiaire incorrectes.')->assertSee('remboursement effectif')->assertDontSee('Approuver le transfert');
    }

    public function test_administrator_can_review_another_agencys_transfer(): void
    {
        $admin = User::factory()->create()->assignRole(Role::Administrator->value);
        $this->actingAs($admin)->post(route('transfers.approve', $this->transfer), ['confirmed' => '1'])->assertSessionHasNoErrors();
        $this->assertSame($admin->id, $this->transfer->refresh()->approved_by);
    }

    public function test_cashiers_cannot_review_even_if_given_the_permission(): void
    {
        foreach ([$this->cashier, $this->destinationCashier] as $cashier) {
            $cashier->givePermissionTo(['transfers.approve', 'transfers.reject']);
            $this->actingAs($cashier)->post(route('transfers.approve', $this->transfer), ['confirmed' => '1'])->assertForbidden();
            $this->post(route('transfers.reject', $this->transfer), ['rejection_reason' => 'Tentative non autorisée'])->assertForbidden();
        }
        $this->assertSame(TransferStatus::Pending, $this->transfer->refresh()->status);
    }

    public function test_manager_from_another_agency_cannot_review_even_with_global_read_access(): void
    {
        $other = User::factory()->for($this->destinationCashier->branch)->create()->assignRole(Role::Manager->value)->givePermissionTo('branches.view-all');
        $this->actingAs($other)->post(route('transfers.approve', $this->transfer), ['confirmed' => '1'])->assertForbidden();
        $this->post(route('transfers.reject', $this->transfer), ['rejection_reason' => 'Motif de rejet'])->assertForbidden();
    }

    public function test_approval_confirmation_is_required(): void
    {
        $this->actingAs($this->manager)->post(route('transfers.approve', $this->transfer), [])->assertSessionHasErrors('confirmed');
        $this->assertSame(TransferStatus::Pending, $this->transfer->refresh()->status);
    }

    #[DataProvider('decisions')]
    public function test_repeating_decision_is_idempotent_and_conflicting_decision_is_refused(string $decision, string $opposite): void
    {
        $payload = ['confirmed' => '1', 'rejection_reason' => 'Erreur de bénéficiaire'];
        $this->actingAs($this->manager)->post(route('transfers.'.$decision, $this->transfer), $payload)->assertSessionHasNoErrors();
        $count = InboxNotification::count();
        $this->post(route('transfers.'.$decision, $this->transfer), $payload)->assertSessionHasNoErrors();
        $this->postJson(route('transfers.'.$opposite, $this->transfer), $payload)->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->assertDatabaseCount('transfer_events', 2);
        $this->assertDatabaseCount('notifications', $count);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public static function decisions(): array
    {
        return [['approve', 'reject'], ['reject', 'approve']];
    }

    public function test_failed_audit_rolls_back_status_and_notifications(): void
    {
        $count = InboxNotification::count();
        Event::listen('eloquent.creating: '.TransferEvent::class, fn () => throw new \RuntimeException('Audit unavailable'));
        try {
            app(ReviewTransfer::class)->handle($this->manager, $this->transfer, TransferStatus::Approved);
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.TransferEvent::class);
        }
        $this->assertSame(TransferStatus::Pending, $this->transfer->refresh()->status);
        $this->assertNull($this->transfer->approved_by);
        $this->assertDatabaseCount('notifications', $count);
    }

    public function test_inactive_destination_or_missing_deposit_blocks_approval(): void
    {
        $this->destinationCashier->branch->update(['status' => 'INACTIVE']);
        $this->actingAs($this->manager)->postJson(route('transfers.approve', $this->transfer), ['confirmed' => '1'])->assertUnprocessable()->assertJsonValidationErrors('decision');
        $orphan = Transfer::factory()->create(['source_branch_id' => $this->cashier->branch_id]);
        $this->postJson(route('transfers.approve', $orphan), ['confirmed' => '1'])->assertUnprocessable()->assertJsonValidationErrors('decision');
    }

    public function test_pending_filter_and_review_menu_help_manager_find_work(): void
    {
        Transfer::factory()->create(['source_branch_id' => $this->cashier->branch_id, 'status' => TransferStatus::Approved]);
        Transfer::factory()->create();
        $this->actingAs($this->manager)->get(route('dashboard'))->assertSee('Transferts à valider');
        $this->get(route('transfers.index', ['status' => 'PENDING']))->assertOk()
            ->assertViewHas('records', fn ($records) => $records->count() === 1 && $records->first()->id === $this->transfer->id);
        $this->actingAs($this->cashier)->get(route('transfers.show', $this->transfer))->assertDontSee('Validation du transfert')->assertDontSee('Transferts à valider');
    }

    public function test_formatted_numbers_are_used_in_screen_and_thermal_receipt(): void
    {
        $this->actingAs($this->manager)->get(route('transfers.show', $this->transfer))->assertOk()
            ->assertSee('100,00 USD')->assertSee('355,99 AED')->assertSee('>3,67</span>', false)->assertSee('>3</span>', false);
        $this->assertSame('3.670000', $this->transfer->refresh()->exchange_rate);
    }

    public function test_guests_cannot_review(): void
    {
        $this->post(route('transfers.approve', $this->transfer), ['confirmed' => '1'])->assertRedirect(route('login'));
        $this->post(route('transfers.reject', $this->transfer), ['rejection_reason' => 'Motif de rejet'])->assertRedirect(route('login'));
    }
}
