<?php

namespace Tests\Feature;

use App\Enums\CashMovementType;
use App\Enums\RecordStatus;
use App\Enums\Role;
use App\Enums\TransferStatus;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\FeeWithdrawal;
use App\Models\FeeWithdrawalItem;
use App\Models\Transfer;
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

class FeeRevenueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00', 'UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_all_endpoints_require_login(): void
    {
        foreach (['fee-revenue.index', 'fee-revenue.export', 'fee-revenue.withdrawals.create'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
        $this->post(route('fee-revenue.withdrawals.store'))->assertRedirect(route('login'));
    }

    #[DataProvider('roles')]
    public function test_policy_limits_reporting_and_withdrawals_by_role(Role $role, bool $view, bool $withdraw): void
    {
        $actor = User::factory()->for(Branch::factory())->create()->assignRole($role->value);
        $actor->givePermissionTo(['reports.view', 'branches.view-all', 'cash.withdraw']);
        $this->assertSame($view, $actor->can('viewFeeRevenue', CashAccount::class));
        $this->assertSame($withdraw, $actor->can('withdrawFeeRevenue', CashAccount::class));
    }

    public static function roles(): array
    {
        return ['admin' => [Role::Administrator, true, true], 'manager' => [Role::Manager, true, false],
            'source cashier' => [Role::SourceCashier, false, false], 'destination cashier' => [Role::DestinationCashier, false, false]];
    }

    public function test_cashier_cannot_access_reports_even_with_accidental_permissions(): void
    {
        $actor = User::factory()->for(Branch::factory())->create()->assignRole(Role::SourceCashier->value)->givePermissionTo('reports.view');
        $this->actingAs($actor)->get(route('fee-revenue.index'))->assertForbidden();
        $this->get(route('fee-revenue.export'))->assertForbidden();
        $this->get(route('fee-revenue.withdrawals.create'))->assertForbidden();
        $this->post(route('fee-revenue.withdrawals.store'))->assertForbidden();
        $this->get(route('dashboard'))->assertDontSee(route('fee-revenue.index'));
    }

    public function test_manager_only_sees_own_source_agency_and_cannot_withdraw(): void
    {
        $own = $this->account();
        $other = $this->account();
        $visible = $this->transfer($own);
        $hidden = $this->transfer($other, ['destination_branch_id' => $own->branch_id]);
        $actor = User::factory()->create(['branch_id' => $own->branch_id])->assignRole(Role::Manager->value)->givePermissionTo('branches.view-all');
        $response = $this->actingAs($actor)->get(route('fee-revenue.index'))->assertSee($visible->reference)->assertDontSee($hidden->reference)->assertDontSee('Retirer des frais');
        $this->assertSame([$own->id], array_keys($response->viewData('totals')));
        $csv = $this->get(route('fee-revenue.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString($visible->reference, $csv);
        $this->assertStringNotContainsString($hidden->reference, $csv);
        $this->get(route('fee-revenue.index', ['branch_id' => $other->branch_id]))->assertNotFound();
        $this->post(route('fee-revenue.withdrawals.store'), $this->input($own))->assertForbidden();
    }

    public function test_totals_use_exact_fees_by_agency_and_currency_and_include_every_page(): void
    {
        $first = $this->account();
        $second = $this->account('CDF');
        $template = $this->transfer($first, ['fee_amount' => '0.10']);
        for ($i = 0; $i < 20; $i++) {
            $this->transfer($first, ['fee_amount' => '0.10', 'sender_id' => $template->sender_id, 'beneficiary_id' => $template->beneficiary_id,
                'destination_branch_id' => $template->destination_branch_id, 'exchange_rate_id' => $template->exchange_rate_id, 'transfer_fee_rule_id' => $template->transfer_fee_rule_id]);
        }
        $this->transfer($second, ['fee_amount' => '900.35']);
        $response = $this->actingAs($this->admin())->get(route('fee-revenue.index', ['page' => 2]))->assertOk();
        $this->assertSame(22, $response->viewData('records')->total());
        $this->assertCount(2, $response->viewData('records'));
        $this->assertSame('2.10', $response->viewData('totals')[$first->id]['fees']);
        $this->assertSame('900.35', $response->viewData('totals')[$second->id]['fees']);
        $filtered = $this->get(route('fee-revenue.index', ['currency' => 'CDF', 'branch_id' => $second->branch_id]));
        $this->assertSame([$second->id], array_keys($filtered->viewData('totals')));
        $csv = $this->get(route('fee-revenue.export', ['page' => 2]))->streamedContent();
        $this->assertCount(23, explode("\n", trim($csv)));
        $this->get(route('dashboard'))->assertSee(route('fee-revenue.index'));
    }

    #[DataProvider('statuses')]
    public function test_only_validated_transfers_generate_withdrawable_fees(TransferStatus $status, bool $included): void
    {
        $account = $this->account();
        $this->transfer($account, ['status' => $status]);
        $response = $this->actingAs($this->admin())->get(route('fee-revenue.index'));
        $this->assertSame($included ? 1 : 0, $response->viewData('records')->total());
    }

    public static function statuses(): array
    {
        return array_map(fn (TransferStatus $status): array => [$status, in_array($status, [TransferStatus::Approved, TransferStatus::ReadyForPayout, TransferStatus::Paid])], TransferStatus::cases());
    }

    public function test_report_requires_a_matching_deposit_and_filters_inclusive_local_collection_dates(): void
    {
        $account = $this->account();
        $account->branch->update(['timezone' => 'Asia/Dubai']);
        $included = $this->transfer($account, [], ['created_at' => '2026-10-20 22:30:00', 'occurred_on' => null]);
        $this->transfer($account, [], ['occurred_on' => '2026-10-20']);
        $this->transfer($account, [], ['occurred_on' => '2026-10-22']);
        $this->transfer($account, [], ['amount' => '99.00']);
        $this->transfer($account, [], ['currency' => 'EUR']);
        Transfer::factory()->create(['source_branch_id' => $account->branch_id, 'status' => TransferStatus::Approved]);
        $response = $this->actingAs($this->admin())->get(route('fee-revenue.index', ['date_from' => '2026-10-21', 'date_to' => '2026-10-21']));
        $this->assertSame([$included->id], $response->viewData('records')->pluck('id')->all());
    }

    public function test_withdrawal_debits_cash_and_allocates_partial_fees_once_even_across_overlapping_periods(): void
    {
        $account = $this->account();
        $first = $this->transfer($account);
        $second = $this->transfer($account);
        $admin = $this->admin();
        $input = $this->input($account, ['amount' => '4.50']);
        $this->actingAs($admin)->get(route('fee-revenue.withdrawals.create', ['cash_account_id' => $account->id]))->assertSee('6,00 USD')->assertSee('100,00 USD');
        $this->post(route('fee-revenue.withdrawals.store'), $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('95.50', $account->refresh()->balance);
        $withdrawal = FeeWithdrawal::sole();
        $this->assertSame('4.50', $withdrawal->movement->amount);
        $this->assertSame('2026-10-21', $withdrawal->movement->occurred_on->toDateString());
        $this->assertSame(CashMovementType::CashOut, $withdrawal->movement->type);
        $this->assertSame($admin->id, $withdrawal->movement->created_by);
        $this->assertSame([$first->id => '3.00', $second->id => '1.50'], $withdrawal->items->pluck('amount', 'transfer_id')->all());
        $this->assertSame(1, $admin->notifications()->count());
        $this->post(route('fee-revenue.withdrawals.store'), $input)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('fee_withdrawals', 1);
        $this->assertSame('95.50', $account->refresh()->balance);
        $this->assertSame(1, $admin->notifications()->count());
        $report = $this->get(route('fee-revenue.index', ['date_from' => '2026-10-20', 'date_to' => '2026-10-22']));
        $this->assertSame('1.50', $report->viewData('totals')[$account->id]['available']);
        $this->post(route('fee-revenue.withdrawals.store'), $this->input($account, ['date_from' => '2026-10-20', 'amount' => '2.00']))->assertSessionHasErrors('amount');
        $this->post(route('fee-revenue.withdrawals.store'), $this->input($account, ['date_from' => '2026-10-20', 'amount' => '1.50']))->assertSessionHasNoErrors();
        $this->assertSame('94.00', $account->refresh()->balance);
        $this->assertSame('0.00', $this->get(route('fee-revenue.index'))->viewData('totals')[$account->id]['available']);
        $this->get(route('treasury.index'))->assertSee('Retrait des frais de transfert');
        $this->assertSame('3.00', $first->refresh()->fee_amount);
    }

    public function test_retry_cannot_reuse_a_key_with_different_period_or_amount(): void
    {
        $account = $this->account();
        $this->transfer($account);
        $input = $this->input($account);
        $this->actingAs($this->admin())->post(route('fee-revenue.withdrawals.store'), $input)->assertSessionHasNoErrors();
        $this->post(route('fee-revenue.withdrawals.store'), [...$input, 'date_from' => '2026-10-02'])->assertSessionHasErrors('submission_key');
        $this->post(route('fee-revenue.withdrawals.store'), [...$input, 'amount' => '2.00'])->assertSessionHasErrors('submission_key');
        $this->assertSame('99.00', $account->refresh()->balance);
        $this->assertDatabaseCount('fee_withdrawal_items', 1);
    }

    public function test_no_fees_or_insufficient_cash_prevent_withdrawal_without_partial_records(): void
    {
        $account = $this->account();
        $this->actingAs($this->admin())->post(route('fee-revenue.withdrawals.store'), $this->input($account))->assertSessionHasErrors('amount');
        $this->transfer($account);
        $account->forceFill(['balance' => '0.50'])->save();
        $this->post(route('fee-revenue.withdrawals.store'), $this->input($account))->assertSessionHasErrors('amount');
        $this->assertSame('0.50', $account->refresh()->balance);
        $this->assertDatabaseCount('fee_withdrawals', 0);
        $this->assertDatabaseCount('fee_withdrawal_items', 0);
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_other_cash_outs_do_not_count_as_fee_withdrawals_but_still_reduce_available_cash(): void
    {
        $account = $this->account();
        $this->transfer($account);
        $input = $this->input($account, ['description' => 'Transport']);
        $this->actingAs($this->admin())->post(route('treasury.cash-outs.store'), $input)->assertSessionHasNoErrors();
        $report = $this->get(route('fee-revenue.index'));
        $this->assertSame('0.00', $report->viewData('totals')[$account->id]['withdrawn']);
        $this->assertSame('3.00', $report->viewData('totals')[$account->id]['available']);
        $this->post(route('fee-revenue.withdrawals.store'), $input)->assertSessionHasErrors('submission_key');
        $this->assertSame('99.00', $account->refresh()->balance);
    }

    public function test_extreme_amounts_keep_the_last_cent_and_ignore_forged_financial_fields(): void
    {
        $account = $this->account();
        $account->forceFill(['balance' => '9999999999999999.99'])->save();
        $transfer = $this->transfer($account, ['amount_received' => '9999999999999999.99', 'fee_amount' => '9999999999999999.99']);
        $this->actingAs($this->admin())->post(route('fee-revenue.withdrawals.store'), $this->input($account, [
            'amount' => '9999999999999999.98', 'available' => '0.00', 'balance_after' => '100.00', 'transfer_id' => $transfer->id, 'currency' => 'EUR',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('0.01', $account->refresh()->balance);
        $this->assertSame('9999999999999999.98', FeeWithdrawalItem::sole()->amount);
        $this->assertNull(FeeWithdrawal::sole()->movement->transfer_id);
        $report = $this->get(route('fee-revenue.index'));
        $this->assertSame('0.01', $report->viewData('totals')[$account->id]['available']);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_withdrawal_inputs_do_not_change_cash(array $overrides, string $error): void
    {
        $account = $this->account();
        $this->transfer($account);
        $this->actingAs($this->admin())->post(route('fee-revenue.withdrawals.store'), $this->input($account, $overrides))->assertSessionHasErrors($error);
        $this->assertSame('100.00', $account->refresh()->balance);
        $this->assertDatabaseCount('fee_withdrawals', 0);
    }

    public static function invalidInputs(): array
    {
        return ['negative' => [['amount' => '-1'], 'amount'], 'precision' => [['amount' => '1.001'], 'amount'],
            'zero' => [['amount' => '0'], 'amount'], 'array' => [['amount' => []], 'amount'],
            'no recipient' => [['recipient_name' => ' '], 'recipient_name'], 'no confirmation' => [['confirmed' => '0'], 'confirmed'],
            'reversed interval' => [['date_from' => '2026-10-23'], 'date_to'], 'invalid date' => [['date_from' => '2026-02-30'], 'date_from'],
            'future withdrawal' => [['occurred_on' => '2026-10-24'], 'occurred_on'], 'array token' => [['submission_key' => []], 'submission_key']];
    }

    public function test_export_has_all_filtered_rows_and_safely_encodes_spreadsheet_cells(): void
    {
        $account = $this->account();
        $account->branch->update(['name' => '=HYPERLINK("evil")']);
        $transfer = $this->transfer($account);
        $this->transfer($account, ['reference' => 'TT-OUTSIDE'], ['occurred_on' => '2026-09-01']);
        $response = $this->actingAs($this->admin())->get(route('fee-revenue.export', ['date_from' => '2026-10-21', 'date_to' => '2026-10-21', 'page' => 2]))->assertOk()->assertDownload('frais-transferts-2026-10-21-2026-10-21.csv');
        $csv = $response->streamedContent();
        $this->assertStringContainsString($transfer->reference, $csv);
        $this->assertStringNotContainsString('TT-OUTSIDE', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('3,00;0,00;3,00', $csv);
    }

    public function test_permission_revocation_and_inactive_cash_account_block_withdrawals(): void
    {
        $account = $this->account();
        $this->transfer($account);
        $admin = $this->admin();
        $account->forceFill(['status' => RecordStatus::Inactive])->save();
        $this->actingAs($admin)->post(route('fee-revenue.withdrawals.store'), $this->input($account))->assertForbidden();
        $admin->roles->first()->revokePermissionTo('reports.view');
        $this->actingAs($admin->fresh())->get(route('fee-revenue.index'))->assertForbidden();
        $this->get(route('fee-revenue.export'))->assertForbidden();
        $this->assertDatabaseCount('fee_withdrawals', 0);
    }

    public function test_failure_after_cash_movement_rolls_back_cash_allocations_and_notifications(): void
    {
        $account = $this->account();
        $this->transfer($account);
        Event::listen('eloquent.creating: '.FeeWithdrawalItem::class, fn () => throw new RuntimeException('Allocation failed'));
        try {
            $this->withoutExceptionHandling()->actingAs($this->admin())->post(route('fee-revenue.withdrawals.store'), $this->input($account));
            $this->fail('Expected allocation failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Allocation failed', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.FeeWithdrawalItem::class);
        }
        $this->assertSame('100.00', $account->refresh()->balance);
        $this->assertDatabaseCount('fee_withdrawals', 0);
        $this->assertDatabaseCount('fee_withdrawal_items', 0);
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_recorded_withdrawals_and_allocations_are_immutable(): void
    {
        $account = $this->account();
        $this->transfer($account);
        $this->actingAs($this->admin())->post(route('fee-revenue.withdrawals.store'), $this->input($account))->assertSessionHasNoErrors();
        foreach ([FeeWithdrawal::sole(), FeeWithdrawalItem::sole()] as $record) {
            try {
                $record->delete();
                $this->fail('Expected immutable history.');
            } catch (LogicException) {
                $this->assertModelExists($record);
            }
        }
    }

    public function test_invalid_report_filters_and_foreign_withdrawal_account_are_rejected(): void
    {
        $account = $this->account();
        $other = $this->account();
        $admin = User::factory()->create(['branch_id' => $account->branch_id])->assignRole(Role::Administrator->value);
        $admin->roles->first()->revokePermissionTo('branches.view-all');
        $this->actingAs($admin)->get(route('fee-revenue.index', ['date_from' => '2026-10-22', 'date_to' => '2026-10-01']))->assertSessionHasErrors('date_to');
        $this->get(route('fee-revenue.export', ['currency' => ['USD']]))->assertSessionHasErrors('currency');
        $this->get(route('fee-revenue.withdrawals.create', ['cash_account_id' => $other->id]))->assertNotFound();
        $this->post(route('fee-revenue.withdrawals.store'), $this->input($other))->assertNotFound();
        $this->assertSame('100.00', $other->refresh()->balance);
    }

    public function test_report_escapes_names_and_history_survives_inactive_agencies(): void
    {
        $account = $this->account();
        $account->branch->update(['name' => '<script>alert(1)</script>']);
        $this->transfer($account);
        $this->actingAs($this->admin())->post(route('fee-revenue.withdrawals.store'), $this->input($account, ['recipient_name' => '<img src=x onerror=alert(1)>']))->assertSessionHasNoErrors();
        $account->branch->update(['status' => RecordStatus::Inactive]);
        $response = $this->get(route('fee-revenue.index'))->assertSee('&lt;script&gt;', false)->assertSee('&lt;img', false)
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x', false)->assertDontSee('Retirer des frais');
        $this->assertSame(1, $response->viewData('withdrawals')->total());
        $this->post(route('fee-revenue.withdrawals.store'), $this->input($account))->assertForbidden();
        $this->assertSame('99.00', $account->refresh()->balance);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole(Role::Administrator->value);
    }

    private function account(string $currency = 'USD'): CashAccount
    {
        return CashAccount::factory()->for(Branch::factory()->state(['default_currency' => $currency]))->create(['currency' => $currency, 'balance' => '100.00']);
    }

    private function transfer(CashAccount $account, array $overrides = [], array $deposit = []): Transfer
    {
        $transfer = Transfer::factory()->create([...['source_branch_id' => $account->branch_id, 'source_currency' => $account->currency, 'status' => TransferStatus::Approved], ...$overrides]);
        CashMovement::factory()->create(['transfer_id' => $transfer->id, 'cash_account_id' => $account->id,
            'currency' => $account->currency, 'amount' => $transfer->amount_received, 'occurred_on' => '2026-10-21', ...$deposit]);

        return $transfer;
    }

    private function input(CashAccount $account, array $overrides = []): array
    {
        return ['cash_account_id' => $account->id, 'date_from' => '2026-10-01', 'date_to' => '2026-10-22',
            'amount' => '1.00', 'occurred_on' => '2026-10-21', 'recipient_name' => 'Marie', 'submission_key' => (string) Str::uuid(), 'confirmed' => '1', ...$overrides];
    }
}
