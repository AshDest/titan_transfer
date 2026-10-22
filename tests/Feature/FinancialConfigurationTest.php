<?php

namespace Tests\Feature;

use App\Actions\Finance\RecordExchangeRate;
use App\Actions\Finance\RecordTransferFeeRule;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\ExchangeRate;
use App\Models\TransferFeeRule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinancialConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_exchange_rate_preserves_the_previous_version_and_author(): void
    {
        $admin = $this->administrator();
        $old = app(RecordExchangeRate::class)->handle($admin, $this->rateInput());

        $new = app(RecordExchangeRate::class)->handle($admin, [
            ...$this->rateInput(), 'rate' => '3.680123', 'effective_from' => '2026-09-23 00:00:00',
            'created_by' => 999999,
        ]);

        $this->assertSame('3.670000', $old->refresh()->rate);
        $this->assertSame('3.680123', $new->rate);
        $this->assertSame('2026-09-22 00:00:00', $old->effective_from->format('Y-m-d H:i:s'));
        $this->assertTrue($new->createdBy->is($admin));
        $this->assertDatabaseCount('exchange_rates', 2);
    }

    public function test_new_fee_rule_preserves_the_previous_version_and_decimal_precision(): void
    {
        $admin = $this->administrator();
        $old = app(RecordTransferFeeRule::class)->handle($admin, $this->feeInput());

        $new = app(RecordTransferFeeRule::class)->handle($admin, [
            ...$this->feeInput(), 'percentage' => '2.5123', 'minimum_amount' => '1501.01',
            'maximum_amount' => '2000.99',
        ]);

        $this->assertSame('3.0000', $old->refresh()->percentage);
        $this->assertSame('2.5123', $new->percentage);
        $this->assertSame('1501.01', $new->minimum_amount);
        $this->assertSame('2000.99', $new->maximum_amount);
        $this->assertTrue($new->createdBy->is($admin));
        $this->assertDatabaseCount('transfer_fee_rules', 2);
    }

    #[DataProvider('invalidRates')]
    public function test_invalid_exchange_rate_is_rejected_without_writing(array $changes, string $field): void
    {
        $admin = $this->administrator();

        try {
            app(RecordExchangeRate::class)->handle($admin, [...$this->rateInput(), ...$changes]);
            $this->fail('Invalid rate must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
            $this->assertDatabaseCount('exchange_rates', 0);
        }
    }

    public static function invalidRates(): array
    {
        return [
            'zero' => [['rate' => '0'], 'rate'],
            'negative' => [['rate' => '-1'], 'rate'],
            'float input' => [['rate' => 3.67], 'rate'],
            'excess precision' => [['rate' => '3.6700001'], 'rate'],
            'overflow' => [['rate' => '1000000000000'], 'rate'],
            'scientific notation' => [['rate' => '1e3'], 'rate'],
            'same currency' => [['to_currency' => 'USD'], 'to_currency'],
            'invalid currency' => [['from_currency' => 'usd'], 'from_currency'],
            'invalid start' => [['effective_from' => 'yesterday'], 'effective_from'],
            'invalid period' => [['effective_until' => '2026-09-21 00:00:00'], 'effective_until'],
        ];
    }

    #[DataProvider('invalidFees')]
    public function test_invalid_fee_rule_is_rejected_without_writing(array $changes, string $field): void
    {
        $admin = $this->administrator();

        try {
            app(RecordTransferFeeRule::class)->handle($admin, [...$this->feeInput(), ...$changes]);
            $this->fail('Invalid fee rule must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
            $this->assertDatabaseCount('transfer_fee_rules', 0);
        }
    }

    public static function invalidFees(): array
    {
        return [
            'negative fee' => [['percentage' => '-1'], 'percentage'],
            'fee over 100%' => [['percentage' => '100.0001'], 'percentage'],
            'fee float' => [['percentage' => 3.0], 'percentage'],
            'fee precision' => [['percentage' => '3.00001'], 'percentage'],
            'amount precision' => [['minimum_amount' => '10.001'], 'minimum_amount'],
            'negative amount' => [['minimum_amount' => '-0.01'], 'minimum_amount'],
            'reversed bounds' => [['minimum_amount' => '501.01', 'maximum_amount' => '501.00'], 'maximum_amount'],
            'large bounds stay exact' => [['minimum_amount' => '9999999999999999.99', 'maximum_amount' => '9999999999999999.98'], 'maximum_amount'],
            'missing source currency' => [['source_currency' => null], 'source_currency'],
            'unknown branch' => [['source_branch_id' => 9999], 'source_branch_id'],
            'invalid period' => [['effective_from' => '2026-09-23 00:00:00', 'effective_until' => '2026-09-22 00:00:00'], 'effective_until'],
            'expiry without start' => [['effective_from' => null, 'effective_until' => '2026-09-22 00:00:00'], 'effective_from'],
        ];
    }

    public function test_fee_rule_cannot_use_the_same_branch_for_both_ends(): void
    {
        $admin = $this->administrator();
        $branch = Branch::factory()->create();

        try {
            app(RecordTransferFeeRule::class)->handle($admin, [
                ...$this->feeInput(), 'source_branch_id' => $branch->id, 'destination_branch_id' => $branch->id,
            ]);
            $this->fail('Identical branches must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('destination_branch_id', $exception->errors());
            $this->assertDatabaseCount('transfer_fee_rules', 0);
        }
    }

    #[DataProvider('historicalModels')]
    public function test_financial_history_refuses_in_place_updates(string $model, string $field, string $value): void
    {
        $record = $model::factory()->create();
        $before = $record->getAttribute($field);

        try {
            $record->update([$field => $value]);
            $this->fail('Historical configuration must not be overwritten.');
        } catch (LogicException) {
            $this->assertSame($before, $record->refresh()->getAttribute($field));
        }
    }

    #[DataProvider('historicalModels')]
    public function test_financial_history_refuses_deletion(string $model, string $field, string $value): void
    {
        $record = $model::factory()->create();

        try {
            $record->delete();
            $this->fail('Historical configuration must not be deleted.');
        } catch (LogicException) {
            $this->assertModelExists($record);
        }
    }

    public static function historicalModels(): array
    {
        return [
            'exchange rate' => [ExchangeRate::class, 'rate', '4.000000'],
            'fee rule' => [TransferFeeRule::class, 'percentage', '5.0000'],
        ];
    }

    private function administrator(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->assignRole(Role::Administrator->value);
    }

    private function rateInput(): array
    {
        return ['from_currency' => 'USD', 'to_currency' => 'AED', 'rate' => '3.670000', 'effective_from' => '2026-09-22 00:00:00'];
    }

    private function feeInput(): array
    {
        return ['name' => 'Standard', 'source_currency' => 'USD', 'percentage' => '3.0000', 'minimum_amount' => '1000.01'];
    }
}
