<?php

namespace Tests\Feature;

use App\Actions\Transfers\CancelTransfer;
use App\Enums\Role;
use App\Enums\TransferStatus;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\FeeWithdrawalItem;
use App\Models\InboxNotification;
use App\Models\Transfer;
use App\Models\TransferEvent;
use App\Models\User;
use App\Services\FeeRevenue;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ImmediateTransferConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $cashier;

    private User $destination;

    private Transfer $transfer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $source = Branch::factory()->create();
        $destination = Branch::factory()->create(['default_currency' => 'AED']);
        $this->manager = User::factory()->for($source)->create()->assignRole(Role::Manager->value);
        $this->cashier = User::factory()->for($source)->create()->assignRole(Role::SourceCashier->value);
        $this->destination = User::factory()->for($destination)->create()->assignRole(Role::DestinationCashier->value);
        $this->transfer = Transfer::factory()->create(['source_branch_id' => $source->id, 'destination_branch_id' => $destination->id, 'status' => TransferStatus::Approved]);
    }

    public function test_manager_cancels_once_with_reason_and_notifies_both_agencies_without_refunding_cash(): void
    {
        $account = CashAccount::factory()->create(['branch_id' => $this->cashier->branch_id, 'balance' => '100']);
        CashMovement::factory()->create(['transfer_id' => $this->transfer->id, 'cash_account_id' => $account->id]);
        $filters = ['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()];
        $this->assertSame(1, app(FeeRevenue::class)->query($this->manager, $filters)->count());
        $original = $this->transfer->only(['amount_received', 'fee_amount', 'net_amount', 'payout_amount', 'parties_snapshot']);
        $this->actingAs($this->manager)->get(route('transfers.show', $this->transfer))->assertSee('Confirmer l’annulation')->assertDontSee('Approuver le transfert');
        $this->post(route('transfers.cancel', $this->transfer), $this->cancellation())->assertSessionHasNoErrors()->assertRedirect(route('transfers.show', $this->transfer));
        $this->assertSame(TransferStatus::Cancelled, $this->transfer->refresh()->status);
        $this->assertSame($original, $this->transfer->only(array_keys($original)));
        $this->assertSame('100.00', $account->refresh()->balance);
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertSame(0, app(FeeRevenue::class)->query($this->manager, $filters)->count());
        $event = TransferEvent::sole();
        $this->assertSame('TRANSFER_CANCELLED', $event->action);
        $this->assertSame('APPROVED', $event->old_values['status']);
        $this->assertSame('Erreur de bénéficiaire', $event->new_values['cancellation_reason']);
        $this->assertSame($this->manager->id, $event->user_id);
        foreach ([$this->cashier, $this->destination] as $user) {
            $this->assertSame('Transfert annulé', $user->notifications()->sole()->data['title']);
        }
        $count = InboxNotification::count();
        $this->post(route('transfers.cancel', $this->transfer), $this->cancellation())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('transfer_events', 1);
        $this->assertDatabaseCount('notifications', $count);
        $this->get(route('transfers.show', $this->transfer))->assertSee('Erreur de bénéficiaire')->assertSee('remboursement effectif')->assertDontSee('Confirmer l’annulation');
        CashAccount::factory()->create(['branch_id' => $this->destination->branch_id, 'currency' => 'AED', 'balance' => '1000']);
        $this->actingAs($this->destination)->post(route('transfers.pay', $this->transfer), ['confirmed' => '1'])->assertSessionHasErrors('payment');
        $this->get(route('transfers.index', ['awaiting_payout' => 1]))->assertViewHas('records', fn ($records): bool => $records->isEmpty());
    }

    public function test_confirmation_and_reason_are_required(): void
    {
        $this->actingAs($this->manager)->post(route('transfers.cancel', $this->transfer), [])->assertSessionHasErrors(['confirmed', 'cancellation_reason']);
        $this->post(route('transfers.cancel', $this->transfer), ['confirmed' => '1', 'cancellation_reason' => '    '])->assertSessionHasErrors('cancellation_reason');
        $this->assertSame(TransferStatus::Approved, $this->transfer->refresh()->status);
        $this->assertDatabaseCount('transfer_events', 0);
    }

    public function test_only_admin_or_source_manager_with_permission_can_cancel(): void
    {
        $other = User::factory()->for(Branch::factory())->create()->assignRole(Role::Manager->value)->givePermissionTo('branches.view-all');
        foreach ([$this->cashier, $this->destination, $other] as $user) {
            $user->givePermissionTo('transfers.cancel');
            $this->actingAs($user)->post(route('transfers.cancel', $this->transfer), $this->cancellation())->assertForbidden();
        }
        $this->manager->revokePermissionTo('transfers.cancel');
        $this->manager->roles->first()->revokePermissionTo('transfers.cancel');
        $this->actingAs($this->manager->fresh())->post(route('transfers.cancel', $this->transfer), $this->cancellation())->assertForbidden();
        $admin = User::factory()->create()->assignRole(Role::Administrator->value);
        $this->actingAs($admin)->post(route('transfers.cancel', $this->transfer), $this->cancellation())->assertSessionHasNoErrors();
    }

    #[DataProvider('cancellableStatuses')]
    public function test_pending_and_ready_transfers_can_also_be_cancelled(TransferStatus $status): void
    {
        $transfer = Transfer::factory()->create(['source_branch_id' => $this->manager->branch_id, 'status' => $status]);
        $this->actingAs($this->manager)->post(route('transfers.cancel', $transfer), $this->cancellation())->assertSessionHasNoErrors();
        $this->assertSame(TransferStatus::Cancelled, $transfer->refresh()->status);
    }

    public static function cancellableStatuses(): array
    {
        return [[TransferStatus::Pending], [TransferStatus::ReadyForPayout]];
    }

    public function test_paid_transfer_cannot_be_cancelled_even_with_a_stale_page(): void
    {
        $stale = $this->transfer;
        CashAccount::factory()->create(['branch_id' => $this->destination->branch_id, 'currency' => 'AED', 'balance' => '1000']);
        $this->actingAs($this->destination)->post(route('transfers.pay', $this->transfer), ['confirmed' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('transfers.cancel', $stale), $this->cancellation())->assertSessionHasErrors('cancellation');
        $this->assertSame(TransferStatus::Paid, $this->transfer->refresh()->status);
        $this->assertSame(0, TransferEvent::where('action', 'TRANSFER_CANCELLED')->count());
    }

    public function test_withdrawn_fees_prevent_cancellation(): void
    {
        FeeWithdrawalItem::factory()->create(['transfer_id' => $this->transfer->id]);
        $this->actingAs($this->manager)->post(route('transfers.cancel', $this->transfer), $this->cancellation())->assertSessionHasErrors('cancellation');
        $this->assertSame(TransferStatus::Approved, $this->transfer->refresh()->status);
    }

    #[DataProvider('uncancellableStatuses')]
    public function test_draft_rejected_and_reversed_transfers_cannot_be_cancelled(TransferStatus $status): void
    {
        $transfer = Transfer::factory()->create(['source_branch_id' => $this->manager->branch_id, 'status' => $status]);
        $this->actingAs($this->manager)->post(route('transfers.cancel', $transfer), $this->cancellation())->assertSessionHasErrors('cancellation');
        $this->assertSame($status, $transfer->refresh()->status);
        $this->assertDatabaseCount('transfer_events', 0);
    }

    public static function uncancellableStatuses(): array
    {
        return [[TransferStatus::Draft], [TransferStatus::Rejected], [TransferStatus::Reversed]];
    }

    public function test_guests_cannot_cancel_transfers(): void
    {
        $this->post(route('transfers.cancel', $this->transfer), $this->cancellation())->assertRedirect(route('login'));
        $this->assertSame(TransferStatus::Approved, $this->transfer->refresh()->status);
    }

    public function test_notification_failure_rolls_back_cancellation_and_audit(): void
    {
        Event::listen('eloquent.creating: '.InboxNotification::class, fn () => throw new \RuntimeException('Notification failed'));
        try {
            app(CancelTransfer::class)->handle($this->manager, $this->transfer, $this->cancellation());
            $this->fail('Expected notification failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Notification failed', $exception->getMessage());
        }
        $this->assertSame(TransferStatus::Approved, $this->transfer->refresh()->status);
        $this->assertDatabaseCount('transfer_events', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    /** @return array{confirmed: string, cancellation_reason: string} */
    private function cancellation(): array
    {
        return ['confirmed' => '1', 'cancellation_reason' => 'Erreur de bénéficiaire'];
    }
}
