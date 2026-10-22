<?php

namespace Tests\Feature;

use App\Enums\CashMovementType;
use App\Enums\RecordStatus;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\InboxNotification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TreasuryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00', 'UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_sees_all_agency_balances_and_zero_balance_agencies_without_creating_accounts(): void
    {
        $first = Branch::factory()->create(['name' => 'Agence Goma']);
        $second = Branch::factory()->create(['name' => 'Agence Dubai']);
        Branch::factory()->create(['name' => 'Agence sans caisse', 'default_currency' => 'CDF']);
        CashAccount::factory()->for($first)->create(['balance' => '1250.50']);
        CashAccount::factory()->for($second)->create(['currency' => 'AED', 'balance' => '900.00']);
        $admin = $this->administrator();

        $this->actingAs($admin)->get(route('dashboard'))->assertSee(route('treasury.index'));
        $response = $this->get(route('treasury.index'))->assertOk()->assertSee('Agence Goma')->assertSee('Agence Dubai')
            ->assertSee('Agence sans caisse')->assertSee('900,00 AED')->assertSee('0,00 CDF');
        $this->assertCount(3, $response->viewData('balances'));
        $this->assertDatabaseCount('cash_accounts', 2);
    }

    #[DataProvider('cashierRoles')]
    public function test_cashiers_only_see_their_own_agency_even_with_an_accidental_global_permission(Role $role): void
    {
        [$actor, $account] = $this->cashierAndAccount($role);
        $actor->givePermissionTo('branches.view-all');
        $foreignBranch = Branch::factory()->create(['name' => 'Hidden agency']);
        $foreign = CashAccount::factory()->for($foreignBranch)->create(['balance' => '999.00']);
        $this->movement($foreign, $actor, ['description' => 'Hidden expense']);
        $this->movement($account, $actor, ['description' => 'Own expense']);
        $this->actingAs($actor)->get(route('treasury.index'))->assertOk()->assertSee('Own expense')->assertDontSee('Hidden agency')->assertDontSee('Hidden expense')->assertDontSee('999,00');
        $this->get(route('treasury.cash-outs.create'))->assertDontSee('Hidden agency');
        $this->get(route('treasury.index', ['branch_id' => $foreignBranch->id]))->assertNotFound();
        $this->post(route('treasury.cash-outs.store'), $this->input($foreign))->assertForbidden();
        $this->assertSame('999.00', $foreign->refresh()->balance);
    }

    public static function cashierRoles(): array
    {
        return [[Role::SourceCashier], [Role::DestinationCashier]];
    }

    public function test_cash_out_stores_the_details_and_atomically_debits_only_the_selected_account(): void
    {
        [$actor, $account] = $this->cashierAndAccount();
        $admin = $this->administrator();
        [$foreignActor, $foreignAccount] = $this->cashierAndAccount();
        $input = $this->input($account, ['amount' => '25.15', 'recipient_name' => ' Marie ', 'description' => ' Transport ',
            'created_by' => $foreignActor->id, 'balance_after' => '99999', 'currency' => 'EUR', 'type' => 'DEPOSIT', 'transfer_id' => 99999]);

        $this->actingAs($actor)->post(route('treasury.cash-outs.store'), $input)->assertRedirect(route('treasury.index', ['branch_id' => $actor->branch_id]))->assertSessionHasNoErrors();

        $movement = CashMovement::sole();
        $this->assertSame('74.85', $account->refresh()->balance);
        $this->assertSame('100.00', $foreignAccount->refresh()->balance);
        $this->assertSame('100.00', $movement->balance_before);
        $this->assertSame('74.85', $movement->balance_after);
        $this->assertSame('25.15', $movement->amount);
        $this->assertSame('USD', $movement->currency);
        $this->assertSame(CashMovementType::CashOut, $movement->type);
        $this->assertNull($movement->transfer_id);
        $this->assertSame($actor->id, $movement->created_by);
        $this->assertSame('2026-10-21', $movement->occurred_on->toDateString());
        $this->assertSame('2026-10-22', $movement->created_at->toDateString());
        $this->assertSame('Marie', $movement->recipient_name);
        $this->assertSame('Transport', $movement->description);
        $this->assertSame(1, $actor->notifications()->count());
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(0, $foreignActor->notifications()->count());
        $notification = $actor->notifications()->sole();
        $this->get(route('notifications.index'))->assertSee('Sortie de caisse enregistrée');
        $this->post(route('notifications.open', $notification))->assertRedirect(route('treasury.index', ['movement_id' => $movement->id]));
        $this->get(route('treasury.index', ['movement_id' => $movement->id]))->assertSee('Marie')->assertSee('Transport')->assertSee('74,85 USD');
    }

    public function test_admin_can_debit_another_agencys_account(): void
    {
        $account = CashAccount::factory()->create(['balance' => '100.00']);
        $admin = $this->administrator();
        $this->actingAs($admin)->post(route('treasury.cash-outs.store'), $this->input($account))->assertSessionHasNoErrors();
        $this->assertSame('90.00', $account->refresh()->balance);
        $this->assertSame($admin->id, CashMovement::sole()->created_by);
    }

    public function test_duplicate_submission_is_idempotent_but_reusing_the_key_for_different_details_is_refused(): void
    {
        [$actor, $account] = $this->cashierAndAccount();
        $input = $this->input($account);
        $this->actingAs($actor);
        for ($i = 0; $i < 2; $i++) {
            $this->post(route('treasury.cash-outs.store'), $input)->assertSessionHasNoErrors();
        }
        $this->post(route('treasury.cash-outs.store'), [...$input, 'amount' => '20.00'])->assertSessionHasErrors('submission_key');
        $this->assertSame('90.00', $account->refresh()->balance);
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertSame(1, $actor->notifications()->count());
    }

    public function test_available_balance_is_rechecked_for_each_new_withdrawal(): void
    {
        [$actor, $account] = $this->cashierAndAccount();
        $this->actingAs($actor)->post(route('treasury.cash-outs.store'), $this->input($account, ['amount' => '80.00']))->assertSessionHasNoErrors();
        $this->post(route('treasury.cash-outs.store'), $this->input($account, ['amount' => '80.00']))->assertSessionHasErrors(['amount' => 'Solde insuffisant pour cette sortie de caisse.']);
        $this->assertSame('20.00', $account->refresh()->balance);
        $this->assertDatabaseCount('cash_movements', 1);
    }

    public function test_large_balances_keep_exact_cents(): void
    {
        [$actor, $account] = $this->cashierAndAccount();
        $account->forceFill(['balance' => '9999999999999999.99'])->save();
        $this->actingAs($actor)->post(route('treasury.cash-outs.store'), $this->input($account, ['amount' => '0.01']))->assertSessionHasNoErrors();
        $this->assertSame('9999999999999999.98', $account->refresh()->balance);
        $this->assertSame('0.01', CashMovement::sole()->amount);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_cash_outs_do_not_change_the_balance_or_history(array $changes, string $field): void
    {
        [$actor, $account] = $this->cashierAndAccount();
        $this->actingAs($actor)->post(route('treasury.cash-outs.store'), $this->input($account, $changes))->assertSessionHasErrors($field);
        $this->assertSame('100.00', $account->refresh()->balance);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public static function invalidInputs(): array
    {
        return [
            [['amount' => '0'], 'amount'], [['amount' => '-1'], 'amount'], [['amount' => '1.001'], 'amount'],
            [['amount' => '1e2'], 'amount'], [['amount' => ['10']], 'amount'], [['amount' => '100.01'], 'amount'],
            [['occurred_on' => '2026-10-23'], 'occurred_on'], [['occurred_on' => '2026-02-30'], 'occurred_on'],
            [['recipient_name' => ' '], 'recipient_name'], [['description' => ' '], 'description'],
            [['confirmed' => '0'], 'confirmed'], [['submission_key' => 'invalid'], 'submission_key'],
        ];
    }

    public function test_inactive_accounts_and_agencies_cannot_be_debited(): void
    {
        [$actor, $account] = $this->cashierAndAccount();
        $account->forceFill(['status' => RecordStatus::Inactive])->save();
        $this->actingAs($actor)->post(route('treasury.cash-outs.store'), $this->input($account))->assertForbidden();
        $account->forceFill(['status' => RecordStatus::Active])->save();
        $account->branch->update(['status' => RecordStatus::Inactive]);
        $this->actingAs($this->administrator())->post(route('treasury.cash-outs.store'), $this->input($account))->assertForbidden();
        $this->assertSame('100.00', $account->refresh()->balance);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_invalid_account_input_returns_to_the_form_with_a_readable_error(): void
    {
        [$actor, $account] = $this->cashierAndAccount();
        $this->actingAs($actor)->followingRedirects()->from(route('treasury.cash-outs.create'))
            ->post(route('treasury.cash-outs.store'), $this->input($account, ['cash_account_id' => [$account->id], 'submission_key' => ['invalid']]))
            ->assertOk()->assertSee('cash_account_id-error');
        $this->assertSame('100.00', $account->refresh()->balance);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_readers_without_withdrawal_permission_and_guests_cannot_create_expenses(): void
    {
        [$cashier, $account] = $this->cashierAndAccount();
        $manager = User::factory()->for($account->branch)->create()->assignRole(Role::Manager->value);
        $this->get(route('treasury.index'))->assertRedirect(route('login'));
        $this->post(route('treasury.cash-outs.store'), $this->input($account))->assertRedirect(route('login'));
        $this->actingAs($manager)->get(route('treasury.index'))->assertOk()->assertDontSee(route('treasury.cash-outs.create'));
        $this->get(route('treasury.cash-outs.create'))->assertForbidden();
        $this->post(route('treasury.cash-outs.store'), $this->input($account))->assertForbidden();
        $cashier->roles->first()->revokePermissionTo('cash.withdraw');
        $this->actingAs($cashier->fresh())->post(route('treasury.cash-outs.store'), $this->input($account))->assertForbidden();
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_combined_history_filters_include_date_boundaries_and_do_not_recalculate_current_balances(): void
    {
        [$actor, $account] = $this->cashierAndAccount();
        $matching = $this->movement($account, $actor, ['occurred_on' => '2026-10-21', 'recipient_name' => 'Matched recipient', 'description' => 'Taxi']);
        $this->movement($account, $actor, ['occurred_on' => '2026-10-20', 'recipient_name' => 'Too early']);
        $this->movement($account, $actor, ['occurred_on' => '2026-10-22', 'recipient_name' => 'Too late']);
        $this->movement($account, $actor, ['occurred_on' => '2026-10-21', 'type' => CashMovementType::CashIn, 'recipient_name' => 'Wrong type']);
        $this->movement($account, $actor, ['occurred_on' => '2026-10-21', 'currency' => 'CDF', 'recipient_name' => 'Wrong currency']);
        $this->movement($account, $actor, ['occurred_on' => '2026-10-21', 'recipient_name' => 'Unrelated search']);
        $foreign = CashAccount::factory()->create();
        $this->movement($foreign, $actor, ['occurred_on' => '2026-10-21', 'recipient_name' => 'Matched foreign recipient']);
        $response = $this->actingAs($this->administrator())->get(route('treasury.index', [
            'branch_id' => $account->branch_id, 'type' => 'CASH_OUT', 'currency' => 'USD', 'date_from' => '2026-10-21', 'date_to' => '2026-10-21', 'q' => 'Matched',
        ]))->assertOk()->assertSee('Matched recipient')->assertDontSee('Matched foreign recipient')->assertSee('100,00 USD');
        $this->assertSame([$matching->id], $response->viewData('records')->pluck('id')->all());
        $this->assertSame('100.00', $account->refresh()->balance);
    }

    public function test_date_filters_and_pagination_keep_the_scope_and_search(): void
    {
        [$actor, $account] = $this->cashierAndAccount();
        for ($i = 0; $i < 27; $i++) {
            $this->movement($account, $actor, ['description' => 'Pagination test']);
        }
        $this->actingAs($actor)->from(route('treasury.index'))->get(route('treasury.index', ['date_from' => '2026-10-22', 'date_to' => '2026-10-20']))->assertSessionHasErrors('date_to');
        $response = $this->get(route('treasury.index', ['q' => 'Pagination', 'date_from' => '2026-10-21', 'date_to' => '2026-10-21', 'page' => 2]))->assertOk();
        $this->assertSame(27, $response->viewData('records')->total());
        $this->assertCount(2, $response->viewData('records'));
        $response->assertSee('q=Pagination')->assertSee('date_from=2026-10-21');
    }

    public function test_recorded_date_uses_agency_timezone_for_existing_transfer_deposits(): void
    {
        $branch = Branch::factory()->create(['timezone' => 'Asia/Dubai']);
        $account = CashAccount::factory()->for($branch)->create();
        $movement = CashMovement::factory()->create(['cash_account_id' => $account->id, 'created_at' => '2026-10-21 22:30:00']);
        $this->assertSame('2026-10-22', $movement->occurred_on->toDateString());
        $response = $this->actingAs($this->administrator())->get(route('treasury.index', ['date_from' => '2026-10-22', 'date_to' => '2026-10-22']));
        $this->assertSame([$movement->id], $response->viewData('records')->pluck('id')->all());
    }

    public function test_history_escapes_recipient_and_reason_and_cannot_be_modified_or_deleted(): void
    {
        [$actor, $account] = $this->cashierAndAccount();
        $movement = $this->movement($account, $actor, ['recipient_name' => '<script>alert(1)</script>', 'description' => '<img src=x onerror=alert(1)>']);
        $this->actingAs($actor)->get(route('treasury.index'))->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false);
        $this->assertFalse($actor->can('update', $movement));
        $this->assertFalse($actor->can('delete', $movement));
        try {
            $movement->forceFill(['amount' => '999.00'])->save();
            $this->fail('Expected immutable history.');
        } catch (LogicException) {
            $this->assertSame('10.00', $movement->refresh()->amount);
        }
    }

    public function test_failed_notification_rolls_back_the_balance_and_movement(): void
    {
        [$actor, $account] = $this->cashierAndAccount();
        Event::listen('eloquent.creating: '.InboxNotification::class, fn () => throw new RuntimeException('Notification failed'));
        try {
            $this->withoutExceptionHandling()->actingAs($actor)->post(route('treasury.cash-outs.store'), $this->input($account));
            $this->fail('Expected notification failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Notification failed', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.InboxNotification::class);
        }
        $this->assertSame('100.00', $account->refresh()->balance);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_upgrade_backfills_existing_history_and_grants_withdrawals_without_removing_other_permissions(): void
    {
        [$actor, $account] = $this->cashierAndAccount();
        $account->branch->update(['timezone' => 'Asia/Dubai']);
        $movement = CashMovement::factory()->create([
            'cash_account_id' => $account->id, 'created_at' => '2026-10-21 22:30:00',
            'amount' => '9999999999999999.99', 'balance_after' => '9999999999999999.99',
        ]);
        $actor->roles->first()->givePermissionTo('cash.adjust');
        $migration = require database_path('migrations/2026_10_22_115958_add_cash_out_fields_to_cash_movements_table.php');
        $migration->down();

        $migration->up();

        $this->assertSame('2026-10-22', $movement->refresh()->occurred_on->toDateString());
        $this->assertSame('9999999999999999.99', $movement->amount);
        $this->assertSame('9999999999999999.99', $movement->balance_after);
        $this->assertNotNull($movement->transfer_id);
        $this->assertTrue($actor->fresh()->can('cash.withdraw'));
        $this->assertTrue($actor->fresh()->can('cash.adjust'));
        $this->assertDatabaseCount('cash_movements', 1);
    }

    private function administrator(): User
    {
        return User::factory()->create()->assignRole(Role::Administrator->value);
    }

    /** @return array{User, CashAccount} */
    private function cashierAndAccount(Role $role = Role::SourceCashier): array
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->for($branch)->create()->assignRole($role->value);
        $account = CashAccount::factory()->for($branch)->create(['balance' => '100.00']);

        return [$actor, $account];
    }

    private function input(CashAccount $account, array $overrides = []): array
    {
        return array_replace(['cash_account_id' => $account->id, 'amount' => '10.00', 'occurred_on' => '2026-10-21',
            'recipient_name' => 'Marie', 'description' => 'Transport', 'submission_key' => (string) Str::uuid(), 'confirmed' => '1'], $overrides);
    }

    private function movement(CashAccount $account, User $actor, array $overrides = []): CashMovement
    {
        return CashMovement::factory()->create(array_replace(['cash_account_id' => $account->id, 'transfer_id' => null,
            'created_by' => $actor->id, 'type' => CashMovementType::CashOut, 'occurred_on' => '2026-10-21',
            'recipient_name' => 'Marie', 'description' => 'Transport', 'amount' => '10.00', 'balance_before' => '100.00', 'balance_after' => '90.00'], $overrides));
    }
}
