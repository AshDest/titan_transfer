<?php

namespace Tests\Feature;

use App\Actions\Customers\RegisterCustomer;
use App\Actions\Transfers\CreateTransfer;
use App\Enums\Role;
use App\Models\Beneficiary;
use App\Models\Branch;
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

class OperationNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $admin;

    private Branch $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->cashier = User::factory()->for(Branch::factory()->state(['default_currency' => 'USD']))->create()->assignRole(Role::SourceCashier->value);
        $this->admin = User::factory()->create()->assignRole(Role::Administrator->value);
        $this->destination = Branch::factory()->create(['default_currency' => 'AED']);
        ExchangeRate::factory()->create();
        TransferFeeRule::factory()->create();
    }

    public function test_transfer_notifies_actor_colleagues_and_administrator_but_not_other_agencies(): void
    {
        $colleague = User::factory()->for($this->cashier->branch)->create()->assignRole(Role::SourceCashier->value);
        $other = User::factory()->for(Branch::factory())->create()->assignRole(Role::SourceCashier->value);
        $destinationCashier = User::factory()->for($this->destination)->create()->assignRole(Role::DestinationCashier->value);
        $withoutDashboard = User::factory()->for($this->cashier->branch)->create()->givePermissionTo('transfers.view');

        $transfer = $this->createTransfer();

        foreach ([$this->cashier, $colleague, $this->admin] as $recipient) {
            $notification = $recipient->notifications()->sole();
            $this->assertSame($transfer->id, $notification->subject_id);
            $this->assertSame(Transfer::class, $notification->subject_type);
            $this->assertStringContainsString($transfer->reference, $notification->data['message']);
            $this->assertNull($notification->read_at);
        }
        foreach ([$other, $destinationCashier, $withoutDashboard] as $recipient) {
            $this->assertSame(0, $recipient->notifications()->count());
        }
    }

    public function test_preview_and_duplicate_confirmation_do_not_create_extra_notifications(): void
    {
        $data = $this->transferInput();
        $action = app(CreateTransfer::class);
        $review = $action->review($this->cashier, $data);
        $this->assertDatabaseCount('notifications', 0);
        $key = (string) Str::uuid();
        $action->handle($this->cashier, $data, $review['fingerprint'], $key, null);
        $action->handle($this->cashier, $data, $review['fingerprint'], $key, null);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('transfers', 1);
    }

    public function test_failed_outer_transaction_rolls_back_inline_people_and_their_notifications(): void
    {
        $data = [...$this->transferInput(), 'sender_mode' => 'new', 'sender' => $this->person(), 'beneficiary_mode' => 'new', 'beneficiary' => $this->person()];
        $action = app(CreateTransfer::class);
        $review = $action->review($this->cashier, $data);
        Event::listen('eloquent.creating: '.TransferEvent::class, fn () => throw new \RuntimeException('Audit unavailable'));
        try {
            $action->handle($this->cashier, $data, $review['fingerprint'], (string) Str::uuid(), null);
            $this->fail('Expected transaction failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.TransferEvent::class);
        }
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('transfers', 0);
    }

    #[DataProvider('operationKinds')]
    public function test_available_business_operations_produce_notifications(string $kind): void
    {
        $this->actingAs($this->admin);
        [$route, $data, $model] = match ($kind) {
            'customer' => ['customers.store', [...$this->person(), 'branch_id' => $this->cashier->branch_id], Customer::class],
            'beneficiary' => ['beneficiaries.store', [...$this->person(), 'branch_id' => $this->cashier->branch_id], Beneficiary::class],
            'branch' => ['branches.store', ['name' => 'Nouvelle agence', 'code' => 'KGL', 'country' => 'Rwanda', 'city' => 'Kigali', 'default_currency' => 'USD', 'timezone' => 'Africa/Kigali'], Branch::class],
            'rate' => ['exchange-rates.store', ['from_currency' => 'USD', 'to_currency' => 'AED', 'rate' => '3.680000', 'effective_from' => now()->format('Y-m-d\TH:i'), 'active' => '1'], ExchangeRate::class],
            'fee' => ['fee-rules.store', ['name' => 'Nouveaux frais', 'source_currency' => 'USD', 'percentage' => '2.5000', 'active' => '1'], TransferFeeRule::class],
        };
        $this->post(route($route), $data)->assertSessionHasNoErrors();
        $notification = $this->admin->notifications()->sole();
        $this->assertSame($model, $notification->subject_type);
        $this->assertNotEmpty($notification->data['message']);
        $this->post(route('notifications.open', $notification->id))->assertRedirect($notification->destinationUrl());
        $this->assertNotNull($notification->refresh()->read_at);
    }

    public static function operationKinds(): array
    {
        return [['customer'], ['beneficiary'], ['branch'], ['rate'], ['fee']];
    }

    public function test_invalid_operation_creates_no_notification(): void
    {
        $this->actingAs($this->cashier)->post(route('customers.store'), ['branch_id' => $this->cashier->branch_id])->assertSessionHasErrors();
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_bell_feed_and_history_show_only_current_users_notifications(): void
    {
        $transfer = $this->createTransfer();
        $this->actingAs($this->cashier)->get(route('dashboard'))->assertOk()->assertSee('Notifications : 1 non lues');
        $response = $this->getJson(route('notifications.feed'))->assertOk()->assertJsonPath('unread_count', 1)->assertSee($transfer->reference);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get(route('notifications.index'))->assertOk()->assertSee('Transfert enregistré')->assertSee($transfer->reference);
        $stranger = User::factory()->for(Branch::factory())->create()->assignRole(Role::SourceCashier->value);
        $this->actingAs($stranger)->getJson(route('notifications.feed'))->assertJsonPath('unread_count', 0)->assertDontSee($transfer->reference);
    }

    public function test_open_marks_only_the_recipients_notification_read_and_links_to_transfer(): void
    {
        $transfer = $this->createTransfer();
        $notification = $this->cashier->notifications()->sole();
        $this->actingAs($this->cashier)->post(route('notifications.open', $notification->id))->assertRedirect(route('transfers.show', $transfer));
        $this->assertNotNull($notification->refresh()->read_at);
        $this->assertNull($this->admin->notifications()->sole()->read_at);
        $this->getJson(route('notifications.feed'))->assertJsonPath('unread_count', 0);
        $this->get(route('notifications.index', ['filter' => 'unread']))
            ->assertViewHas('records', fn ($records) => $records->isEmpty());
    }

    public function test_read_all_does_not_change_another_users_notifications(): void
    {
        $this->createTransfer();
        $this->createTransfer();
        $this->actingAs($this->cashier)->post(route('notifications.read-all'))->assertRedirect(route('notifications.index'));
        $this->assertSame(0, $this->cashier->unreadNotifications()->count());
        $this->assertSame(2, $this->admin->unreadNotifications()->count());
    }

    public function test_other_users_notification_ids_are_not_accessible_even_to_an_administrator(): void
    {
        $this->createTransfer();
        $notification = $this->cashier->notifications()->sole();
        $this->actingAs($this->admin)->post(route('notifications.open', $notification->id))->assertNotFound();
        $this->post(route('notifications.read', $notification->id))->assertNotFound();
        $this->assertNull($notification->refresh()->read_at);
    }

    public function test_notifications_are_hidden_when_branch_assignment_changes(): void
    {
        $transfer = $this->createTransfer();
        $notification = $this->cashier->notifications()->sole();
        $this->cashier->branch()->associate($this->destination);
        $this->cashier->save();
        $this->actingAs($this->cashier->fresh())->getJson(route('notifications.feed'))->assertJsonPath('unread_count', 0)->assertDontSee($transfer->reference);
        $this->post(route('notifications.open', $notification->id))->assertNotFound();
    }

    public function test_notifications_are_hidden_when_permission_is_revoked(): void
    {
        $transfer = $this->createTransfer();
        $this->cashier->syncRoles([])->syncPermissions(['dashboard.view']);
        $this->actingAs($this->cashier->fresh())->get(route('notifications.index'))->assertOk()->assertDontSee($transfer->reference);
        $this->getJson(route('notifications.feed'))->assertJsonPath('unread_count', 0);
    }

    public function test_notification_text_is_escaped_in_html_and_polling_feed(): void
    {
        app(RegisterCustomer::class)->handle($this->cashier, $this->cashier->branch, [...$this->person(), 'first_name' => '<script>alert(1)</script>']);
        $this->actingAs($this->cashier)->get(route('notifications.index'))->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $html = $this->getJson(route('notifications.feed'))->assertOk()->json('html');
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_notification_storage_failure_rolls_back_operation_instead_of_reporting_false_success(): void
    {
        Event::listen('eloquent.creating: '.InboxNotification::class, fn () => throw new \RuntimeException('Notification storage unavailable'));
        try {
            app(RegisterCustomer::class)->handle($this->cashier, $this->cashier->branch, $this->person());
            $this->fail('Expected storage failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Notification storage unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.InboxNotification::class);
        }
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_guest_and_inactive_agency_cannot_access_notifications(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
        $this->getJson(route('notifications.feed'))->assertUnauthorized();
        $this->post(route('notifications.read-all'))->assertRedirect(route('login'));
        $this->cashier->branch->update(['status' => 'INACTIVE']);
        $this->actingAs($this->cashier->fresh())->getJson(route('notifications.feed'))->assertForbidden();
    }

    public function test_marking_one_notification_read_is_idempotent(): void
    {
        $this->createTransfer();
        $notification = $this->cashier->notifications()->sole();
        $this->actingAs($this->cashier)->post(route('notifications.read', $notification->id))->assertRedirect(route('notifications.index'));
        $readAt = $notification->refresh()->read_at->toIso8601String();
        $this->travel(5)->minutes();
        $this->post(route('notifications.read', $notification->id))->assertRedirect(route('notifications.index'));
        $this->assertSame($readAt, $notification->refresh()->read_at->toIso8601String());
    }

    private function person(): array
    {
        return ['first_name' => 'Marie', 'last_name' => 'Exemple', 'phone' => '+243970000001', 'country' => 'RDC'];
    }

    private function transferInput(): array
    {
        $sender = Customer::factory()->create(['branch_id' => $this->cashier->branch_id]);
        $beneficiary = Beneficiary::factory()->create(['branch_id' => $this->cashier->branch_id, 'customer_id' => $sender->id]);

        return ['source_branch_id' => $this->cashier->branch_id, 'destination_branch_id' => $this->destination->id,
            'sender_mode' => 'existing', 'sender_id' => $sender->id, 'beneficiary_mode' => 'existing', 'beneficiary_id' => $beneficiary->id, 'amount_received' => '100.00'];
    }

    private function createTransfer(): Transfer
    {
        $data = $this->transferInput();
        $action = app(CreateTransfer::class);
        $review = $action->review($this->cashier, $data);

        return $action->handle($this->cashier, $data, $review['fingerprint'], (string) Str::uuid(), null);
    }
}
