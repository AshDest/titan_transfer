<?php

namespace Tests\Feature;

use App\Enums\FeeType;
use App\Enums\Role;
use App\Enums\TransferStatus;
use App\Models\Branch;
use App\Models\CashAccount;
use App\Models\ExchangeRate;
use App\Models\Transfer;
use App\Models\TransferEvent;
use App\Models\TransferFeeRule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomTransferFeesTest extends TestCase
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
        ExchangeRate::factory()->create(['rate' => '3.67']);
        $this->actingAs($this->cashier);
    }

    #[DataProvider('customPercentages')]
    public function test_custom_percentage_replaces_configured_fixed_fees_and_is_saved_on_receipt(string $percentage, string $stored, string $fee, string $payout): void
    {
        $rule = TransferFeeRule::factory()->fixed('15')->create(['maximum_amount' => '999.99']);
        $preview = $this->post(route('transfers.preview'), $this->input(['custom_fee_percentage' => $percentage]))->assertOk()
            ->assertSee('Pourcentage personnalisé')->assertDontSee('Tranche de frais appliquée');
        $this->assertNull($preview->viewData('fee'));
        $this->assertSame($fee, $preview->viewData('quote')->feeAmount);
        $this->assertSame($payout, $preview->viewData('quote')->payoutAmount);
        $token = $preview->viewData('token');
        for ($i = 0; $i < 2; $i++) {
            $this->post(route('transfers.store'), ['review_token' => $token, 'cash_received' => '1', 'custom_fee_percentage' => '99', 'fee_mode' => 'configured'])
                ->assertSessionHasNoErrors();
        }
        $transfer = Transfer::sole();
        $this->assertNull($transfer->transfer_fee_rule_id);
        $this->assertSame(FeeType::Percentage, $transfer->fee_type);
        $this->assertSame($stored, $transfer->fee_percentage);
        $this->assertSame($fee, $transfer->fee_amount);
        $this->assertSame($payout, $transfer->payout_amount);
        $this->assertSame(TransferStatus::Approved, $transfer->status);
        $this->assertSame('100.00', CashAccount::sole()->balance);
        $this->assertSame('15.00', $rule->refresh()->fixed_amount);
        $this->assertDatabaseCount('transfer_fee_rules', 1);
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertSame('custom_percentage', TransferEvent::sole()->new_values['fee_mode']);
        $this->get(route('transfers.show', $transfer))->assertOk()->assertSee('Pourcentage personnalisé')->assertSee('Frais personnalisés');
        $this->get(route('transfers.index'))->assertOk()->assertSee($transfer->reference);
        $this->post(route('fee-rules.store'), [])->assertForbidden();
    }

    public static function customPercentages(): array
    {
        return [
            'zero' => ['0', '0.0000', '0.00', '367.00'],
            'decimal zero' => ['0.00', '0.0000', '0.00', '367.00'],
            'custom percentage' => ['2.5', '2.5000', '2.50', '357.83'],
            'French decimal' => [' 2,5 ', '2.5000', '2.50', '357.83'],
            'four decimals' => ['1.2345', '1.2345', '1.23', '362.49'],
        ];
    }

    public function test_custom_percentage_does_not_require_a_fee_rule_even_above_1000(): void
    {
        $preview = $this->post(route('transfers.preview'), $this->input(['amount_received' => '2000', 'custom_fee_percentage' => '0']))->assertOk();
        $this->assertSame('7340.00', $preview->viewData('quote')->payoutAmount);
        $this->post(route('transfers.store'), ['review_token' => $preview->viewData('token'), 'cash_received' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('0.00', Transfer::sole()->fee_amount);
        $this->assertDatabaseCount('transfer_fee_rules', 0);
    }

    public function test_custom_percentage_ignores_conflicting_rules_and_later_tariff_changes(): void
    {
        TransferFeeRule::factory()->fixed('15')->create(['maximum_amount' => '500']);
        TransferFeeRule::factory()->fixed('20')->create(['maximum_amount' => '999.99']);
        $preview = $this->post(route('transfers.preview'), $this->input())->assertOk();
        TransferFeeRule::factory()->create(['percentage' => '30']);
        $this->post(route('transfers.store'), ['review_token' => $preview->viewData('token'), 'cash_received' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('2.00', Transfer::sole()->fee_amount);
    }

    public function test_configured_mode_is_default_and_ignores_a_stale_custom_percentage(): void
    {
        $rule = TransferFeeRule::factory()->fixed('15')->create(['maximum_amount' => '999.99']);
        $input = $this->input(['custom_fee_percentage' => '0']);
        unset($input['fee_mode']);
        $preview = $this->post(route('transfers.preview'), $input)->assertOk();
        $this->assertSame($rule->id, $preview->viewData('fee')->id);
        $this->assertSame('15.00', $preview->viewData('quote')->feeAmount);
        $this->assertArrayNotHasKey('custom_fee_percentage', $preview->viewData('data'));
        $this->post(route('transfers.store'), ['review_token' => $preview->viewData('token'), 'cash_received' => '1'])->assertSessionHasNoErrors();
        $this->assertSame($rule->id, Transfer::sole()->transfer_fee_rule_id);
    }

    #[DataProvider('invalidPercentages')]
    public function test_invalid_custom_percentages_are_rejected(mixed $percentage): void
    {
        $this->post(route('transfers.preview'), $this->input(['custom_fee_percentage' => $percentage]))->assertSessionHasErrors('custom_fee_percentage');
        $this->assertDatabaseCount('transfers', 0);
    }

    public static function invalidPercentages(): array
    {
        return [[null], [''], ['-1'], ['100'], ['101'], ['1.23456'], ['1e1'], ['abc'], [[]], [2.5]];
    }

    public function test_revision_preserves_zero_and_returning_to_configured_mode_uses_the_tariff(): void
    {
        TransferFeeRule::factory()->fixed('10')->create(['maximum_amount' => '999.99']);
        $preview = $this->post(route('transfers.preview'), $this->input(['custom_fee_percentage' => '0']))->assertOk();
        $this->post(route('transfers.revise'), ['review_token' => $preview->viewData('token')])
            ->assertSessionHasInput('fee_mode', 'custom_percentage')->assertSessionHasInput('custom_fee_percentage', '0');
        $this->get(route('transfers.create'))->assertOk()->assertSee('value="0"', false)->assertSee('Pourcentage personnalisé');
        $this->post(route('transfers.preview'), $this->input(['fee_mode' => 'configured', 'custom_fee_percentage' => '0']))
            ->assertOk()->assertViewHas('quote', fn ($quote): bool => $quote->feeAmount === '10.00');
    }

    public function test_custom_fees_still_require_a_valid_exchange_rate_and_recheck_rate_at_confirmation(): void
    {
        $preview = $this->post(route('transfers.preview'), $this->input())->assertOk();
        ExchangeRate::factory()->create(['rate' => '4']);
        $this->post(route('transfers.store'), ['review_token' => $preview->viewData('token'), 'cash_received' => '1'])->assertSessionHasErrors('review_token');
        $destination = Branch::factory()->create(['default_currency' => 'EUR']);
        $this->post(route('transfers.preview'), $this->input(['destination_branch_id' => $destination->id]))->assertSessionHasErrors('destination_branch_id');
        $this->assertDatabaseCount('transfers', 0);
    }

    public function test_migration_preserves_existing_rules_and_refuses_to_erase_custom_fee_history(): void
    {
        $existing = Transfer::factory()->create();
        $before = $existing->refresh()->getAttributes();
        $migration = require database_path('migrations/2026_11_04_092736_allow_custom_fees_on_transfers.php');
        $migration->down();
        $migration->up();
        $this->assertSame($before, $existing->refresh()->getAttributes());
        Transfer::factory()->create(['transfer_fee_rule_id' => null]);
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function input(array $changes = []): array
    {
        return ['source_branch_id' => $this->cashier->branch_id, 'destination_branch_id' => $this->destination->id,
            'amount_received' => '100', 'fee_mode' => 'custom_percentage', 'custom_fee_percentage' => '2',
            'sender_mode' => 'new', 'sender' => ['full_name' => 'Marie Test', 'phone' => '+243970000001'],
            'beneficiary_mode' => 'new', 'beneficiary' => ['full_name' => 'Paul Test', 'phone' => '+971500000001'], ...$changes];
    }
}
