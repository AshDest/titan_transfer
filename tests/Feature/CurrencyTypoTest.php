<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\ExchangeRate;
use App\Models\TransferFeeRule;
use App\Models\User;
use App\Services\TransferPricing;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CurrencyTypoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00', 'UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actingAs(User::factory()->create()->assignRole(Role::Administrator->value));
    }

    public function test_agency_creation_and_editing_reject_ead_with_a_specific_message(): void
    {
        $input = ['name' => 'Titan Express Dubai', 'code' => 'DXB', 'country' => 'Émirats arabes unis', 'city' => 'Dubai', 'default_currency' => 'EAD', 'timezone' => 'Asia/Dubai'];
        $this->post(route('branches.store'), $input)->assertSessionHasErrors(['default_currency' => 'Le code du dirham des Émirats est AED, et non EAD.']);
        $this->assertDatabaseCount('branches', 0);
        $branch = Branch::factory()->create(['default_currency' => 'AED']);
        $this->put(route('branches.update', $branch), [...$input, 'status' => 'ACTIVE'])->assertSessionHasErrors('default_currency');
        $this->assertSame('AED', $branch->refresh()->default_currency);
    }

    #[DataProvider('rateCurrencies')]
    public function test_exchange_rates_cannot_be_configured_with_ead(string $field): void
    {
        $input = ['from_currency' => 'USD', 'to_currency' => 'AED', 'rate' => '3.67', 'effective_from' => '2026-10-21T00:00', 'active' => '1'];
        $this->post(route('exchange-rates.store'), [...$input, $field => 'EAD'])->assertSessionHasErrors([$field => 'Le code du dirham des Émirats est AED, et non EAD.']);
        $this->assertDatabaseCount('exchange_rates', 0);
    }

    public static function rateCurrencies(): array
    {
        return ['source' => ['from_currency'], 'destination' => ['to_currency']];
    }

    public function test_fee_rules_cannot_be_configured_with_ead(): void
    {
        $this->post(route('fee-rules.store'), ['name' => 'Frais Dubai', 'source_currency' => 'EAD', 'percentage' => '3', 'active' => '1'])
            ->assertSessionHasErrors(['source_currency' => 'Le code du dirham des Émirats est AED, et non EAD.']);
        $this->assertDatabaseCount('transfer_fee_rules', 0);
    }

    public function test_correcting_an_unused_agencys_currency_restores_the_usd_aed_quote(): void
    {
        $source = Branch::factory()->create(['default_currency' => 'USD']);
        $destination = Branch::factory()->create(['name' => 'Titan Express Dubai', 'default_currency' => 'EAD']);
        ExchangeRate::factory()->create(['from_currency' => 'USD', 'to_currency' => 'AED', 'rate' => '3.67', 'active' => true, 'effective_from' => '2026-10-21 00:00:00']);
        TransferFeeRule::factory()->create(['source_branch_id' => null, 'destination_branch_id' => null, 'source_currency' => 'USD', 'percentage' => '3', 'active' => true]);
        try {
            app(TransferPricing::class)->quote($source, $destination, '100.00');
            $this->fail('A misspelled currency must not silently use a different currency rate.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('USD → EAD', $exception->errors()['destination_branch_id'][0]);
        }
        $this->put(route('branches.update', $destination), [...$destination->only(['name', 'code', 'country', 'city', 'timezone']), 'default_currency' => 'AED', 'status' => 'ACTIVE'])
            ->assertRedirect(route('branches.index'))->assertSessionHasNoErrors();
        $quote = app(TransferPricing::class)->quote($source, $destination->refresh(), '100.00');
        $this->assertSame('355.99', $quote['quote']->payoutAmount);
        $this->assertSame('AED', $destination->default_currency);
    }
}
