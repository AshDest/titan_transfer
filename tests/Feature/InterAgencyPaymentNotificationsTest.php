<?php

namespace Tests\Feature;

use App\Actions\Transfers\CreateTransfer;
use App\Enums\CashMovementType;
use App\Enums\Role;
use App\Enums\TransferStatus;
use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Transfer;
use App\Models\TransferFeeRule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InterAgencyPaymentNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $goma;

    private User $dubai;

    private User $manager;

    private User $otherCashier;

    private Transfer $transfer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $source = Branch::factory()->create(['name' => 'Goma', 'default_currency' => 'USD']);
        $destination = Branch::factory()->create(['name' => 'Dubai', 'default_currency' => 'AED']);
        $this->goma = User::factory()->for($source)->create()->assignRole(Role::SourceCashier->value);
        $this->dubai = User::factory()->for($destination)->create(['name' => 'Caissier Dubai'])->assignRole(Role::DestinationCashier->value);
        $this->manager = User::factory()->for($source)->create()->assignRole(Role::Manager->value);
        $this->otherCashier = User::factory()->for(Branch::factory())->create()->assignRole(Role::DestinationCashier->value);
        ExchangeRate::factory()->create();
        TransferFeeRule::factory()->create();
        $sender = Customer::factory()->for($source)->create();
        $beneficiary = Beneficiary::factory()->create(['branch_id' => $source->id, 'customer_id' => $sender->id]);
        $input = ['source_branch_id' => $source->id, 'destination_branch_id' => $destination->id, 'amount_received' => '100',
            'sender_mode' => 'existing', 'sender_id' => $sender->id, 'beneficiary_mode' => 'existing', 'beneficiary_id' => $beneficiary->id];
        $action = app(CreateTransfer::class);
        $review = $action->review($this->goma, $input);
        $this->transfer = $action->handle($this->goma, $input, $review['fingerprint'], (string) Str::uuid(), null);
    }

    public function test_dubai_opens_its_alert_confirms_payment_and_goma_receives_confirmation_once(): void
    {
        $this->assertSame(0, $this->dubai->notifications()->count());
        $this->approve();
        $alert = $this->dubai->notifications()->sole();
        $this->assertSame('Transfert à payer', $alert->data['title']);
        $this->assertStringContainsString('Goma', $alert->data['message']);
        $this->assertStringContainsString('355,99 AED', $alert->data['message']);
        $this->actingAs($this->dubai)->get(route('dashboard'))->assertSee('Transferts à payer');
        $this->get(route('notifications.feed'))->assertOk()->assertJsonPath('unread_count', 1);
        $this->post(route('notifications.open', $alert->id))->assertRedirect(route('transfers.show', $this->transfer));
        $this->assertNotNull($alert->refresh()->read_at);
        $this->get(route('transfers.show', $this->transfer))->assertSee('La confirmation notifiera Goma.')->assertSee('Confirmer le paiement');
        CashAccount::factory()->create(['branch_id' => $this->dubai->branch_id, 'currency' => 'AED', 'balance' => '1000']);
        $this->post(route('transfers.pay', $this->transfer), ['confirmed' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(TransferStatus::Paid, $this->transfer->refresh()->status);
        foreach ([$this->goma, $this->manager] as $recipient) {
            $paid = $recipient->notifications()->get()->filter(fn ($notification): bool => $notification->data['event'] === 'paid')->sole();
            $this->assertSame('Transfert payé', $paid->data['title']);
            $this->assertStringContainsString('Caissier Dubai a confirmé', $paid->data['message']);
            $this->assertStringContainsString('355,99 AED', $paid->data['message']);
            $this->assertStringContainsString('Dubai', $paid->data['message']);
            $this->actingAs($recipient)->post(route('notifications.open', $paid->id))->assertRedirect(route('transfers.show', $this->transfer));
        }
        $this->assertSame(0, $this->otherCashier->notifications()->count());
        $this->actingAs($this->dubai)->post(route('transfers.pay', $this->transfer), ['confirmed' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->goma->notifications()->get()->filter(fn ($notification): bool => $notification->data['event'] === 'paid')->count());
        $this->assertSame(1, CashMovement::where('type', CashMovementType::Payout)->count());
        $this->get(route('transfers.index', ['awaiting_payout' => 1]))->assertViewHas('records', fn ($records): bool => $records->total() === 0);
    }

    public function test_payout_queue_contains_only_unpaid_incoming_transfers_and_preserves_filters(): void
    {
        $this->approve();
        $ready = Transfer::factory()->create(['destination_branch_id' => $this->dubai->branch_id, 'status' => TransferStatus::ReadyForPayout]);
        Transfer::factory()->create(['source_branch_id' => $this->dubai->branch_id, 'status' => TransferStatus::Approved]);
        Transfer::factory()->create(['destination_branch_id' => $this->otherCashier->branch_id, 'status' => TransferStatus::Approved]);
        foreach ([TransferStatus::Pending, TransferStatus::Rejected, TransferStatus::Paid] as $status) {
            Transfer::factory()->create(['destination_branch_id' => $this->dubai->branch_id, 'status' => $status]);
        }
        $this->dubai->givePermissionTo('branches.view-all');
        $page = $this->actingAs($this->dubai)->get(route('transfers.index', ['awaiting_payout' => 1]))->assertOk();
        $this->assertEqualsCanonicalizing([$this->transfer->id, $ready->id], $page->viewData('records')->pluck('id')->all());
        $page->assertSee('name="awaiting_payout" value="1"', false)->assertSee('Confirmer la remise des fonds');
        $this->assertSame('711.98', $page->viewData('totals')['AED']['payout']);
        $this->get(route('transfers.index', ['awaiting_payout' => 1, 'status' => 'READY_FOR_PAYOUT', 'q' => $ready->reference,
            'date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]))
            ->assertViewHas('records', fn ($records): bool => $records->total() === 1 && $records->first()->id === $ready->id);
    }

    public function test_failed_or_unconfirmed_payment_does_not_notify_goma_of_payment(): void
    {
        $this->approve();
        $this->actingAs($this->dubai)->post(route('transfers.pay', $this->transfer))->assertSessionHasErrors('confirmed');
        $this->post(route('transfers.pay', $this->transfer), ['confirmed' => '1'])->assertSessionHasErrors('payment');
        $this->assertFalse($this->goma->notifications()->get()->contains(fn ($notification): bool => $notification->data['event'] === 'paid'));
        $this->assertSame(TransferStatus::Approved, $this->transfer->refresh()->status);
    }

    public function test_source_cashier_cannot_open_payout_queue_and_other_agency_cannot_open_alert(): void
    {
        $this->approve();
        $this->actingAs($this->goma)->get(route('dashboard'))->assertDontSee('Transferts à payer');
        $this->get(route('transfers.index', ['awaiting_payout' => 1]))->assertForbidden();
        $alert = $this->dubai->notifications()->sole();
        $this->actingAs($this->otherCashier)->post(route('notifications.open', $alert->id))->assertNotFound();
        $this->post(route('transfers.pay', $this->transfer), ['confirmed' => '1'])->assertForbidden();
    }

    private function approve(): void
    {
        $this->actingAs($this->manager)->post(route('transfers.approve', $this->transfer), ['confirmed' => '1'])->assertSessionHasNoErrors();
    }
}
