<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\Role;
use App\Enums\TransferStatus;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\Transfer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TransferListPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $goma;

    private User $dubai;

    private Transfer $transfer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 27)->setTime(10, 15));
        $this->seed(RolesAndPermissionsSeeder::class);
        $source = Branch::factory()->create(['name' => 'Goma', 'timezone' => 'Africa/Lubumbashi']);
        $destination = Branch::factory()->create(['name' => 'Dubai', 'default_currency' => 'AED', 'timezone' => 'Asia/Dubai']);
        $this->goma = User::factory()->for($source)->create()->assignRole(Role::SourceCashier->value);
        $this->dubai = User::factory()->for($destination)->create(['name' => 'Caissier Dubai'])->assignRole(Role::DestinationCashier->value);
    }

    public function test_cashier_confirms_from_list_and_source_sees_who_served_beneficiary_and_when(): void
    {
        $this->transfer = $this->createTransfer();
        $account = CashAccount::factory()->create(['branch_id' => $this->dubai->branch_id, 'currency' => 'AED', 'balance' => '1000.00']);
        $page = $this->actingAs($this->dubai)->get(route('transfers.index', ['awaiting_payout' => 1]))->assertOk()
            ->assertSee('data-payment-dialog="payment-'.$this->transfer->id.'"', false)
            ->assertSee('action="'.route('transfers.pay', $this->transfer).'"', false)
            ->assertSee('name="confirmed" value="1" required', false)
            ->assertSee('355,99 AED');
        $this->assertTrue($page->viewData('paymentAvailability')[$this->transfer->id]['can_pay']);

        $this->post(route('transfers.pay', $this->transfer), ['confirmed' => '1'])
            ->assertSessionHasNoErrors()->assertRedirect(route('transfers.show', $this->transfer))
            ->assertSessionHas('print_transfer', $this->transfer->uuid);
        $this->assertSame('644.01', $account->refresh()->balance);

        $this->actingAs($this->goma)->get(route('transfers.index', ['status' => 'PAID']))->assertOk()
            ->assertSee($this->transfer->reference)->assertSee('Bénéficiaire servi')->assertSee('Par Caissier Dubai')
            ->assertSee('27/10/2026 12:15')->assertSee('Africa/Lubumbashi')
            ->assertSee($this->transfer->parties_snapshot['beneficiary']['first_name'])
            ->assertDontSee('data-payment-dialog=', false)->assertDontSee('Solde en caisse')
            ->assertViewHas('paymentAvailability', []);
        $this->assertTrue($this->goma->notifications()->get()->contains(fn ($notification): bool => $notification->data['event'] === 'paid'));
    }

    #[DataProvider('unavailableCash')]
    public function test_list_disables_confirmation_when_cash_is_unavailable(?string $balance, RecordStatus $status): void
    {
        $this->transfer = $this->createTransfer();
        if ($balance !== null) {
            CashAccount::factory()->create(['branch_id' => $this->dubai->branch_id, 'currency' => 'AED', 'balance' => $balance, 'status' => $status]);
        }
        $page = $this->actingAs($this->dubai)->get(route('transfers.index'))->assertOk();
        $this->assertFalse($page->viewData('paymentAvailability')[$this->transfer->id]['can_pay']);
        $this->assertMatchesRegularExpression('/<button[^>]+data-payment-dialog="payment-'.$this->transfer->id.'"[^>]+disabled/', $page->getContent());
        $this->post(route('transfers.pay', $this->transfer), ['confirmed' => '1'])->assertSessionHasErrors('payment');
        $this->assertSame(TransferStatus::Approved, $this->transfer->refresh()->status);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public static function unavailableCash(): array
    {
        return [
            'missing account' => [null, RecordStatus::Active],
            'insufficient balance' => ['355.98', RecordStatus::Active],
            'inactive account' => ['1000.00', RecordStatus::Inactive],
        ];
    }

    public function test_source_and_foreign_cashiers_cannot_confirm_from_the_list(): void
    {
        $this->transfer = $this->createTransfer();
        $this->actingAs($this->goma)->get(route('transfers.index'))->assertOk()
            ->assertSee($this->transfer->reference)->assertDontSee('data-payment-dialog=', false);
        $foreign = User::factory()->for(Branch::factory())->create()->assignRole(Role::DestinationCashier->value);
        $foreign->givePermissionTo('branches.view-all');
        $this->actingAs($foreign)->get(route('transfers.index'))->assertOk()
            ->assertSee($this->transfer->reference)->assertDontSee('data-payment-dialog=', false);
        $this->post(route('transfers.pay', $this->transfer), ['confirmed' => '1'])->assertForbidden();
    }

    public function test_served_filter_preserves_search_and_dates_and_handles_legacy_paid_transfers(): void
    {
        $this->transfer = $this->createTransfer(TransferStatus::Paid);
        Transfer::factory()->create(['status' => TransferStatus::Paid]);
        $filters = ['q' => $this->transfer->reference, 'date_from' => now()->toDateString(), 'date_to' => now()->toDateString()];
        $this->actingAs($this->dubai)->get(route('transfers.index', [...$filters, 'awaiting_payout' => 1]))->assertOk()
            ->assertSee(route('transfers.index', [...$filters, 'status' => 'PAID']));
        $this->actingAs($this->goma)->get(route('transfers.index', [...$filters, 'status' => 'PAID']))->assertOk()
            ->assertViewHas('records', fn ($records): bool => $records->total() === 1 && $records->first()->id === $this->transfer->id)
            ->assertSee('Bénéficiaire servi')->assertDontSee('Par Caissier Dubai')
            ->assertDontSee('data-payment-dialog=', false);
    }

    public function test_pending_transfer_has_no_payment_action_even_with_sufficient_balance(): void
    {
        $this->transfer = $this->createTransfer(TransferStatus::Pending);
        CashAccount::factory()->create(['branch_id' => $this->dubai->branch_id, 'currency' => 'AED', 'balance' => '1000.00']);
        $this->actingAs($this->dubai)->get(route('transfers.index'))->assertOk()
            ->assertSee($this->transfer->reference)->assertDontSee('data-payment-dialog=', false);
    }

    private function createTransfer(TransferStatus $status = TransferStatus::Approved): Transfer
    {
        return Transfer::factory()->create([
            'source_branch_id' => $this->goma->branch_id,
            'destination_branch_id' => $this->dubai->branch_id,
            'status' => $status,
        ]);
    }
}
