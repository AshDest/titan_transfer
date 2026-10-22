<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\ExchangeRate;
use App\Models\InboxNotification;
use App\Models\Transfer;
use App\Models\User;
use App\Services\TransferPricing;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ExchangeRateManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_activation_selects_one_rate_for_the_exact_pair_and_preserves_other_pairs_and_snapshots(): void
    {
        $old = ExchangeRate::factory()->create();
        $other = ExchangeRate::factory()->create(['rate' => '4.000000']);
        $selected = ExchangeRate::factory()->create(['active' => false, 'rate' => '5.000000']);
        $reverse = ExchangeRate::factory()->create(['from_currency' => 'AED', 'to_currency' => 'USD']);
        $unrelated = ExchangeRate::factory()->create(['to_currency' => 'CDF']);
        $transfer = Transfer::factory()->create(['exchange_rate_id' => $old->id]);
        $before = $transfer->only(['exchange_rate_id', 'exchange_rate', 'payout_amount', 'parties_snapshot']);
        $admin = $this->administrator();

        $this->actingAs($admin)->post(route('exchange-rates.activate', $selected))
            ->assertRedirect(route('exchange-rates.index'))->assertSessionHasNoErrors();

        $this->assertTrue($selected->refresh()->active);
        $this->assertFalse($old->refresh()->active);
        $this->assertFalse($other->refresh()->active);
        $this->assertTrue($reverse->refresh()->active);
        $this->assertTrue($unrelated->refresh()->active);
        $this->assertSame($before, $transfer->refresh()->only(array_keys($before)));
        $this->assertSame('activated', $admin->notifications()->sole()->data['event']);
        $source = Branch::factory()->create(['default_currency' => 'USD']);
        $destination = Branch::factory()->create(['default_currency' => 'AED']);
        $quote = app(TransferPricing::class)->quote($source, $destination, '100.00');
        $this->assertSame($selected->id, $quote['rate']->id);
        $this->assertSame('485.00', $quote['quote']->payoutAmount);
    }

    public function test_activating_an_already_active_rate_disables_existing_duplicates_and_is_idempotent(): void
    {
        $selected = ExchangeRate::factory()->create();
        $other = ExchangeRate::factory()->create();
        $admin = $this->administrator();
        $this->actingAs($admin);
        for ($i = 0; $i < 2; $i++) {
            $this->post(route('exchange-rates.activate', $selected))->assertSessionHasNoErrors();
        }
        $this->assertFalse($other->refresh()->active);
        $this->assertTrue($selected->refresh()->active);
        $this->assertSame(1, $admin->notifications()->count());
    }

    public function test_deactivation_does_not_silently_reactivate_an_old_rate(): void
    {
        $old = ExchangeRate::factory()->create(['active' => false]);
        $selected = ExchangeRate::factory()->create();
        $admin = $this->administrator();
        $this->actingAs($admin)->post(route('exchange-rates.deactivate', $selected))->assertSessionHasNoErrors();
        $this->assertFalse($old->refresh()->active);
        $this->assertFalse($selected->refresh()->active);
        $this->assertSame('deactivated', $admin->notifications()->sole()->data['event']);
    }

    #[DataProvider('invalidActivationPeriods')]
    public function test_invalid_period_cannot_disable_the_current_rate(string $period): void
    {
        $current = ExchangeRate::factory()->create();
        $attributes = $period === 'future' ? ['effective_from' => now()->addSecond()]
            : ['effective_from' => now()->subHour(), 'effective_until' => now()];
        $rate = ExchangeRate::factory()->create(['active' => false, ...$attributes]);
        $this->actingAs($this->administrator())->followingRedirects()->post(route('exchange-rates.activate', $rate))
            ->assertOk()->assertSee('Ce taux est futur ou expiré.');
        $this->assertTrue($current->refresh()->active);
        $this->assertFalse($rate->refresh()->active);
        $this->assertDatabaseCount('notifications', 0);
    }

    public static function invalidActivationPeriods(): array
    {
        return [['future'], ['expired']];
    }

    public function test_creation_of_an_active_rate_is_exclusive_and_inactive_drafts_do_not_replace_it(): void
    {
        $previous = ExchangeRate::factory()->create();
        $this->actingAs($this->administrator())->post(route('exchange-rates.store'), $this->input())
            ->assertRedirect(route('exchange-rates.index'))->assertSessionHasNoErrors();
        $active = ExchangeRate::where('active', true)->sole();
        $this->assertFalse($previous->refresh()->active);
        $this->assertSame('4.500000', $active->rate);
        $this->post(route('exchange-rates.store'), $this->input(['active' => '0', 'effective_from' => now()->addDay()->format('Y-m-d\TH:i')]))
            ->assertSessionHasNoErrors();
        $this->assertTrue($active->refresh()->active);
        $this->assertSame(1, ExchangeRate::where('active', true)->count());
    }

    public function test_creating_a_future_active_rate_returns_an_error_and_rolls_back(): void
    {
        $previous = ExchangeRate::factory()->create();
        $this->actingAs($this->administrator())->followingRedirects()->from(route('exchange-rates.create'))
            ->post(route('exchange-rates.store'), $this->input(['effective_from' => now()->addDay()->format('Y-m-d\TH:i')]))
            ->assertOk()->assertSee('Ce taux est futur ou expiré.');
        $this->assertTrue($previous->refresh()->active);
        $this->assertDatabaseCount('exchange_rates', 1);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_editing_creates_a_new_version_without_overwriting_history_or_changing_the_pair(): void
    {
        $rate = ExchangeRate::factory()->create();
        $original = $rate->only(['rate', 'effective_from', 'created_by']);
        $admin = $this->administrator();
        $this->actingAs($admin)->get(route('exchange-rates.edit', $rate))->assertOk()->assertSee('Enregistrer la nouvelle version');
        $this->put(route('exchange-rates.update', $rate), $this->input(['from_currency' => 'EUR', 'to_currency' => 'CDF', 'created_by' => 9999]))
            ->assertRedirect(route('exchange-rates.index'))->assertSessionHasNoErrors();
        $this->assertFalse($rate->refresh()->active);
        $this->assertSame($original['rate'], $rate->rate);
        $this->assertSame($original['created_by'], $rate->created_by);
        $this->assertEquals($original['effective_from'], $rate->effective_from);
        $new = ExchangeRate::where('active', true)->sole();
        $this->assertSame('USD', $new->from_currency);
        $this->assertSame('AED', $new->to_currency);
        $this->assertSame('4.500000', $new->rate);
        $this->assertSame($admin->id, $new->created_by);
        $this->assertDatabaseCount('exchange_rates', 2);
    }

    public function test_editing_an_active_rate_as_inactive_disables_the_original(): void
    {
        $rate = ExchangeRate::factory()->create();
        $this->actingAs($this->administrator())->put(route('exchange-rates.update', $rate), $this->input(['active' => '0']))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('exchange_rates', 2);
        $this->assertSame(0, ExchangeRate::where('active', true)->count());
    }

    public function test_only_confirmed_inactive_unused_rates_can_be_deleted(): void
    {
        $unused = ExchangeRate::factory()->create(['active' => false]);
        $active = ExchangeRate::factory()->create();
        $used = ExchangeRate::factory()->create(['active' => false]);
        Transfer::factory()->create(['exchange_rate_id' => $used->id]);
        $this->actingAs($this->administrator());
        foreach ([$active, $used] as $rate) {
            $this->delete(route('exchange-rates.destroy', $rate), ['confirmation' => '1'])->assertSessionHasErrors('deletion');
            $this->assertModelExists($rate);
        }
        $this->delete(route('exchange-rates.destroy', $unused))->assertSessionHasErrors('confirmation');
        $this->assertModelExists($unused);
        $this->delete(route('exchange-rates.destroy', $unused), ['confirmation' => '1'])->assertRedirect(route('exchange-rates.index'))->assertSessionHasNoErrors();
        $this->assertModelMissing($unused);
        $this->assertDatabaseCount('transfers', 1);
    }

    public function test_admin_actions_use_icons_and_are_hidden_from_cashiers(): void
    {
        $rate = ExchangeRate::factory()->create();
        $this->actingAs($this->administrator())->get(route('exchange-rates.index'))
            ->assertSee(route('exchange-rates.edit', $rate))->assertSee(route('exchange-rates.activate', $rate))
            ->assertSee(route('exchange-rates.deactivate', $rate))->assertSee('aria-label="Modifier le taux"', false);
        $cashier = User::factory()->for(Branch::factory())->create()->assignRole(Role::SourceCashier->value);
        $this->actingAs($cashier)->get(route('exchange-rates.index'))->assertDontSee(route('exchange-rates.edit', $rate))
            ->assertDontSee(route('exchange-rates.activate', $rate))->assertDontSee(route('exchange-rates.deactivate', $rate));
    }

    #[DataProvider('restrictedRoles')]
    public function test_non_admins_cannot_use_the_actions_even_with_manage_permission(Role $role): void
    {
        $rate = ExchangeRate::factory()->create();
        $actor = User::factory()->for(Branch::factory())->create()->assignRole($role->value)->givePermissionTo('exchange-rates.manage');
        $this->actingAs($actor)->get(route('exchange-rates.edit', $rate))->assertForbidden();
        $this->put(route('exchange-rates.update', $rate), $this->input())->assertForbidden();
        $this->post(route('exchange-rates.activate', $rate))->assertForbidden();
        $this->post(route('exchange-rates.deactivate', $rate))->assertForbidden();
        $this->delete(route('exchange-rates.destroy', $rate), ['confirmation' => '1'])->assertForbidden();
        $this->assertDatabaseCount('exchange_rates', 1);
        $this->assertTrue($rate->refresh()->active);
    }

    public static function restrictedRoles(): array
    {
        return [[Role::SourceCashier], [Role::DestinationCashier], [Role::Manager]];
    }

    public function test_guests_cannot_change_rates(): void
    {
        $rate = ExchangeRate::factory()->create();
        $this->get(route('exchange-rates.edit', $rate))->assertRedirect(route('login'));
        $this->put(route('exchange-rates.update', $rate), $this->input())->assertRedirect(route('login'));
        $this->post(route('exchange-rates.activate', $rate))->assertRedirect(route('login'));
        $this->post(route('exchange-rates.deactivate', $rate))->assertRedirect(route('login'));
        $this->delete(route('exchange-rates.destroy', $rate), ['confirmation' => '1'])->assertRedirect(route('login'));
    }

    public function test_failed_notification_rolls_back_the_entire_activation(): void
    {
        $old = ExchangeRate::factory()->create();
        $selected = ExchangeRate::factory()->create(['active' => false]);
        $admin = $this->administrator();
        Event::listen('eloquent.creating: '.InboxNotification::class, fn () => throw new RuntimeException('Notification failed'));
        try {
            $this->withoutExceptionHandling()->actingAs($admin)->post(route('exchange-rates.activate', $selected));
            $this->fail('Expected notification failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Notification failed', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.InboxNotification::class);
        }
        $this->assertTrue($old->refresh()->active);
        $this->assertFalse($selected->refresh()->active);
        $this->assertDatabaseCount('notifications', 0);
    }

    private function administrator(): User
    {
        return User::factory()->create()->assignRole(Role::Administrator->value);
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['from_currency' => 'USD', 'to_currency' => 'AED', 'rate' => '4.500000',
            'effective_from' => now()->subMinute()->format('Y-m-d\TH:i:s'), 'effective_until' => '', 'active' => '1'], $overrides);
    }
}
