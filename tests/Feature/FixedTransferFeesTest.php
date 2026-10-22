<?php

namespace Tests\Feature;

use App\Enums\FeeType;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\ExchangeRate;
use App\Models\Transfer;
use App\Models\TransferFeeRule;
use App\Models\User;
use App\Services\TransferCalculator;
use App\Services\TransferPricing;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FixedTransferFeesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00', 'UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_create_any_number_of_fixed_tiers_and_percentage_above_1000(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        foreach ([['0', '50', '1'], ['50.01', '100', '2'], ['100.01', '250', '5'], ['250.01', '500', '8'], ['500.01', '1000', '10']] as [$min, $max, $fee]) {
            $this->post(route('fee-rules.store'), $this->ruleInput(['minimum_amount' => $min, 'maximum_amount' => $max, 'fixed_amount' => $fee]))->assertSessionHasNoErrors();
        }
        $this->post(route('fee-rules.store'), $this->ruleInput(['fee_type' => 'percentage', 'percentage' => '2.5123', 'minimum_amount' => '1000.01', 'maximum_amount' => null]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('transfer_fee_rules', 6);
        $first = TransferFeeRule::first();
        $this->assertSame(FeeType::Fixed, $first->fee_type);
        $this->assertSame('1.00', $first->fixed_amount);
        $this->assertSame('0.0000', $first->percentage);
        $this->assertSame($admin->id, $first->created_by);
        $this->assertSame(6, $admin->notifications()->count());
        $this->assertStringContainsString('montant fixe', $admin->notifications()->oldest()->first()->data['message']);
        $last = TransferFeeRule::latest('id')->first();
        $this->assertNull($last->fixed_amount);
        $this->assertSame('2.5123', $last->percentage);
        $this->get(route('fee-rules.index'))->assertSee('Montant fixe')->assertSee('1,00 USD');
        $this->get(route('fee-rules.create'))->assertSee('data-fee-rule-form', false)->assertSee('fixed_amount');
    }

    #[DataProvider('invalidRules')]
    public function test_invalid_modes_values_and_usd_intervals_are_rejected(array $overrides, string $error): void
    {
        $this->actingAs($this->admin())->post(route('fee-rules.store'), $this->ruleInput($overrides))->assertSessionHasErrors($error);
        $this->assertDatabaseCount('transfer_fee_rules', 0);
    }

    public static function invalidRules(): array
    {
        return [
            'missing fixed fee' => [['fixed_amount' => null], 'fixed_amount'],
            'negative' => [['fixed_amount' => '-2'], 'fixed_amount'],
            'three decimals' => [['fixed_amount' => '5.001'], 'fixed_amount'],
            'float' => [['fixed_amount' => 5.0], 'fixed_amount'],
            'array' => [['fixed_amount' => []], 'fixed_amount'],
            'unknown mode' => [['fee_type' => 'other'], 'fee_type'],
            'unbounded fixed fee' => [['maximum_amount' => null], 'maximum_amount'],
            'fixed above threshold' => [['maximum_amount' => '1000.01'], 'maximum_amount'],
            'percentage below threshold' => [['fee_type' => 'percentage', 'percentage' => '2', 'minimum_amount' => '999', 'maximum_amount' => null], 'minimum_amount'],
            'percentage at threshold' => [['fee_type' => 'percentage', 'percentage' => '2', 'minimum_amount' => '1000', 'maximum_amount' => null], 'minimum_amount'],
            'reversed tier' => [['minimum_amount' => '500', 'maximum_amount' => '100'], 'maximum_amount'],
        ];
    }

    #[DataProvider('tierBoundaries')]
    public function test_fee_mode_changes_at_the_exact_tier_boundaries(string $amount, string $fee, FeeType $type): void
    {
        [$source, $destination] = $this->corridor();
        TransferFeeRule::factory()->fixed('5.00')->create(['minimum_amount' => '0', 'maximum_amount' => '100']);
        TransferFeeRule::factory()->fixed('15.00')->create(['minimum_amount' => '100.01', 'maximum_amount' => '1000']);
        TransferFeeRule::factory()->create(['minimum_amount' => '1000.01', 'percentage' => '2.0000']);
        $quote = app(TransferPricing::class)->quote($source, $destination, $amount)['quote'];
        $this->assertSame($fee, $quote->feeAmount);
        $this->assertSame($type, $quote->feeType);
    }

    public static function tierBoundaries(): array
    {
        return [['50', '5.00', FeeType::Fixed], ['100', '5.00', FeeType::Fixed], ['100.01', '15.00', FeeType::Fixed],
            ['1000', '15.00', FeeType::Fixed], ['1000.01', '20.00', FeeType::Percentage], ['2000', '40.00', FeeType::Percentage]];
    }

    public function test_fixed_fee_snapshot_is_saved_and_receipts_stay_correct_after_tariff_changes(): void
    {
        [$source, $destination] = $this->corridor();
        $admin = $this->admin();
        $rule = TransferFeeRule::factory()->fixed('5.25')->create(['minimum_amount' => '0', 'maximum_amount' => '1000']);
        $input = $this->transferInput($source, $destination);
        $preview = $this->actingAs($admin)->post(route('transfers.preview'), $input)->assertOk()->assertSee('Frais fixes : 5,25 USD');
        $this->assertSame('347.73', $preview->viewData('quote')->payoutAmount);
        $this->post(route('transfers.store'), ['review_token' => $preview->viewData('token'), 'cash_received' => '1'])->assertSessionHasNoErrors();
        $transfer = Transfer::sole();
        $this->assertSame(FeeType::Fixed, $transfer->fee_type);
        $this->assertSame('5.25', $transfer->fee_amount);
        $this->assertSame('0.0000', $transfer->fee_percentage);
        $this->assertSame('94.75', $transfer->net_amount);
        $this->assertSame('100.00', CashAccount::sole()->balance);
        $this->assertSame($rule->id, $transfer->transfer_fee_rule_id);
        $this->post(route('transfers.approve', $transfer), ['confirmed' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('5.25', array_values($this->get(route('fee-revenue.index'))->viewData('totals'))[0]['fees']);
        $this->post(route('fee-rules.store'), $this->ruleInput(['fixed_amount' => '8', 'minimum_amount' => '0', 'maximum_amount' => '1000']))->assertSessionHasNoErrors();
        $this->get(route('transfers.show', $transfer))->assertSee('Frais fixes')->assertSee('5,25 USD')->assertDontSee('Pourcentage exact : 0.0000');
        $this->get(route('transfers.index'))->assertSee('Montant fixe');
        $this->assertSame('5.25', $transfer->refresh()->fee_amount);
    }

    public function test_price_change_between_preview_and_confirmation_requires_a_new_review(): void
    {
        [$source, $destination] = $this->corridor();
        TransferFeeRule::factory()->fixed('5')->create(['minimum_amount' => '0', 'maximum_amount' => '1000']);
        $preview = $this->actingAs($this->admin())->post(route('transfers.preview'), $this->transferInput($source, $destination))->assertOk();
        $this->post(route('fee-rules.store'), $this->ruleInput(['fixed_amount' => '7']))->assertSessionHasNoErrors();
        $this->post(route('transfers.store'), ['review_token' => $preview->viewData('token'), 'cash_received' => '1'])->assertSessionHasErrors('review_token');
        $this->assertDatabaseCount('transfers', 0);
        $this->assertDatabaseCount('cash_accounts', 0);
    }

    public function test_disabling_the_old_unbounded_percentage_allows_new_fixed_tiers_without_erasing_history(): void
    {
        [$source, $destination] = $this->corridor();
        $old = TransferFeeRule::factory()->create(['minimum_amount' => null, 'maximum_amount' => null]);
        $this->actingAs($this->admin())->post(route('fee-rules.deactivate', $old))->assertRedirect(route('fee-rules.index'))->assertSessionHasNoErrors();
        $this->assertTrue($old->refresh()->active);
        $this->assertFalse(TransferFeeRule::latest('id')->first()->active);
        $this->post(route('fee-rules.store'), $this->ruleInput())->assertSessionHasNoErrors();
        $quote = app(TransferPricing::class)->quote($source, $destination, '100')['quote'];
        $this->assertSame(FeeType::Fixed, $quote->feeType);
        $this->assertSame('5.00', $quote->feeAmount);
        $this->assertDatabaseCount('transfer_fee_rules', 3);
    }

    public function test_removing_a_tariff_after_preview_prevents_recording_a_transfer_with_it(): void
    {
        [$source, $destination] = $this->corridor();
        $rule = TransferFeeRule::factory()->fixed('5')->create(['maximum_amount' => '1000']);
        $preview = $this->actingAs($this->admin())->post(route('transfers.preview'), $this->transferInput($source, $destination))->assertOk();
        $this->delete(route('fee-rules.destroy', $rule), ['confirmation' => '1'])->assertSessionHasNoErrors();
        $this->post(route('transfers.store'), ['review_token' => $preview->viewData('token'), 'cash_received' => '1'])->assertSessionHasErrors();
        $this->assertDatabaseCount('transfers', 0);
        $this->assertDatabaseCount('cash_accounts', 0);
    }

    public function test_cashier_cannot_create_or_disable_fees(): void
    {
        $cashier = User::factory()->for(Branch::factory())->create()->assignRole(Role::SourceCashier->value);
        $rule = TransferFeeRule::factory()->fixed()->create();
        $this->actingAs($cashier)->post(route('fee-rules.store'), $this->ruleInput())->assertForbidden();
        $this->post(route('fee-rules.deactivate', $rule))->assertForbidden();
        $this->assertDatabaseCount('transfer_fee_rules', 1);
    }

    #[DataProvider('fixedCalculations')]
    public function test_fixed_calculation_preserves_cents_without_percentage_conversion(string $amount, string $fee, string $rate, string $net, string $payout): void
    {
        $quote = app(TransferCalculator::class)->calculate($amount, $fee, $rate, FeeType::Fixed);
        $this->assertSame($net, $quote->netAmount);
        $this->assertSame($payout, $quote->payoutAmount);
    }

    public static function fixedCalculations(): array
    {
        return [['100', '5.25', '3.67', '94.75', '347.73'], ['0.03', '0.01', '1', '0.02', '0.02'],
            ['100', '0', '1', '100.00', '100.00'], ['9999999999999999.99', '0.01', '1', '9999999999999999.98', '9999999999999999.98']];
    }

    #[DataProvider('excessiveFees')]
    public function test_fees_cannot_consume_the_whole_received_amount(string $fee): void
    {
        $this->expectException(ValidationException::class);
        app(TransferCalculator::class)->calculate('5.00', $fee, '1', FeeType::Fixed);
    }

    public static function excessiveFees(): array
    {
        return [['5.00'], ['5.01']];
    }

    public function test_non_usd_fixed_amounts_do_not_apply_a_usd_threshold_as_a_currency_conversion(): void
    {
        $this->actingAs($this->admin())->post(route('fee-rules.store'), $this->ruleInput(['source_currency' => 'CDF', 'fixed_amount' => '2500', 'maximum_amount' => '100000']))->assertSessionHasNoErrors();
        $this->assertSame('2500.00', TransferFeeRule::sole()->fixed_amount);
    }

    public function test_disabling_a_specific_tariff_does_not_fall_back_to_a_generic_tariff(): void
    {
        [$source, $destination] = $this->corridor();
        TransferFeeRule::factory()->fixed('5')->create(['maximum_amount' => '1000']);
        $specific = TransferFeeRule::factory()->fixed('8')->create(['source_branch_id' => $source->id, 'destination_branch_id' => $destination->id, 'maximum_amount' => '1000']);
        $this->actingAs($this->admin())->post(route('fee-rules.deactivate', $specific))->assertSessionHasNoErrors();
        $this->post(route('transfers.preview'), $this->transferInput($source, $destination))->assertSessionHasErrors('amount_received');
    }

    public function test_migration_preserves_existing_percentage_tariffs_and_transfer_snapshots(): void
    {
        $transfer = Transfer::factory()->create();
        $rule = TransferFeeRule::findOrFail($transfer->transfer_fee_rule_id);
        $before = $transfer->only(['amount_received', 'fee_percentage', 'fee_amount', 'net_amount', 'payout_amount']);
        $migration = require database_path('migrations/2026_10_22_130436_add_fixed_fees_to_transfer_fee_rules_and_transfers.php');
        $migration->down();
        $migration->up();
        $this->assertSame(FeeType::Percentage, $transfer->refresh()->fee_type);
        $this->assertSame($before, $transfer->only(array_keys($before)));
        $this->assertSame(FeeType::Percentage, $rule->refresh()->fee_type);
        $this->assertNull($rule->fixed_amount);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole(Role::Administrator->value);
    }

    private function ruleInput(array $overrides = []): array
    {
        return ['name' => 'Tranche fixe', 'source_currency' => 'USD', 'fee_type' => 'fixed', 'fixed_amount' => '5',
            'minimum_amount' => '0', 'maximum_amount' => '1000', 'active' => '1', 'effective_from' => now()->format('Y-m-d H:i:s'), ...$overrides];
    }

    /** @return array{Branch, Branch} */
    private function corridor(): array
    {
        $source = Branch::factory()->create(['default_currency' => 'USD']);
        $destination = Branch::factory()->create(['default_currency' => 'AED']);
        ExchangeRate::factory()->create(['from_currency' => 'USD', 'to_currency' => 'AED', 'rate' => '3.67']);

        return [$source, $destination];
    }

    private function transferInput(Branch $source, Branch $destination): array
    {
        return ['source_branch_id' => $source->id, 'destination_branch_id' => $destination->id, 'amount_received' => '100.00',
            'sender_mode' => 'new', 'sender' => ['first_name' => 'Marie', 'last_name' => 'Test', 'phone' => '+243970000001', 'country' => 'RDC'],
            'beneficiary_mode' => 'new', 'beneficiary' => ['first_name' => 'Paul', 'last_name' => 'Test', 'phone' => '+971500000001', 'country' => 'Émirats arabes unis']];
    }
}
