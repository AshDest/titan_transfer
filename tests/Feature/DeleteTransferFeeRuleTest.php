<?php

namespace Tests\Feature;

use App\Actions\Finance\DeleteTransferFeeRule;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Transfer;
use App\Models\TransferFeeRule;
use App\Models\User;
use App\Services\TransferPricing;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeleteTransferFeeRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_remove_a_used_legacy_fee_without_changing_transfers_or_receipts(): void
    {
        $admin = $this->admin();
        $rule = TransferFeeRule::factory()->create(['name' => 'Ancien tarif proportionnel']);
        $transfer = Transfer::factory()->create(['transfer_fee_rule_id' => $rule->id]);
        $snapshot = $transfer->refresh()->getAttributes();

        $this->actingAs($admin)->get(route('fee-rules.index'))
            ->assertSee('Supprimer la règle de frais')->assertSee(route('fee-rules.destroy', $rule));
        $this->delete(route('fee-rules.destroy', $rule), ['confirmation' => '1'])
            ->assertRedirect(route('fee-rules.index'))->assertSessionHasNoErrors();

        $this->assertNotNull($rule->refresh()->deleted_at);
        $this->assertSame('3.0000', $rule->percentage);
        $this->assertSame($snapshot, $transfer->refresh()->getAttributes());
        $this->get(route('fee-rules.index'))->assertViewHas('records', fn ($records): bool => $records->total() === 0);
        $this->get(route('transfers.show', $transfer))->assertOk()->assertSee('3,00 USD')->assertSee('355,99 AED');
        $notification = $admin->notifications()->sole();
        $this->assertSame('deleted', $notification->data['event']);
        $this->assertSame($rule->id, $notification->data['subject_id']);
        $this->assertSame('Règle de frais supprimée', $notification->data['title']);

        $this->delete(route('fee-rules.destroy', $rule), ['confirmation' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(1, $admin->notifications()->count());
        $this->post(route('fee-rules.deactivate', $rule))->assertNotFound();
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_only_admins_can_remove_fees_even_with_management_permission(array $roles): void
    {
        $user = User::factory()->for(Branch::factory())->create()->assignRole($roles);
        $user->givePermissionTo(Permission::FeeRulesManage->value);
        $rule = TransferFeeRule::factory()->create();
        $this->actingAs($user)->delete(route('fee-rules.destroy', $rule), ['confirmation' => '1'])->assertForbidden();
        $this->assertNull($rule->refresh()->deleted_at);
        $this->get(route('fee-rules.index'))->assertDontSee('Supprimer la règle de frais');

        $this->expectException(AuthorizationException::class);
        app(DeleteTransferFeeRule::class)->handle($user, $rule);
    }

    public static function unauthorizedRoles(): array
    {
        return [
            'source cashier' => [[Role::SourceCashier->value]],
            'destination cashier' => [[Role::DestinationCashier->value]],
            'manager' => [[Role::Manager->value]],
            'admin with cashier role' => [[Role::Administrator->value, Role::SourceCashier->value]],
        ];
    }

    public function test_confirmation_and_authentication_are_required(): void
    {
        $rule = TransferFeeRule::factory()->create();
        $this->delete(route('fee-rules.destroy', $rule), ['confirmation' => '1'])->assertRedirect(route('login'));
        $this->actingAs($this->admin())->from(route('fee-rules.index'))
            ->delete(route('fee-rules.destroy', $rule))->assertSessionHasErrors('confirmation');
        $this->assertNull($rule->refresh()->deleted_at);
    }

    public function test_removing_the_latest_version_does_not_reactivate_an_older_version(): void
    {
        [$source, $destination] = $this->corridor();
        TransferFeeRule::factory()->fixed('4')->create(['maximum_amount' => '1000']);
        $latest = TransferFeeRule::factory()->fixed('5')->create(['maximum_amount' => '1000']);
        $this->assertSame('5.00', app(TransferPricing::class)->quote($source, $destination, '100')['quote']->feeAmount);
        app(DeleteTransferFeeRule::class)->handle($this->admin(), $latest);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Aucune règle de frais active');
        app(TransferPricing::class)->quote($source, $destination, '100');
    }

    public function test_old_overlapping_fees_can_be_removed_to_allow_new_fixed_tiers(): void
    {
        [$source, $destination] = $this->corridor();
        $legacy = TransferFeeRule::factory()->create();
        $fixed = TransferFeeRule::factory()->fixed('5')->create(['maximum_amount' => '1000']);
        $above = TransferFeeRule::factory()->create(['minimum_amount' => '1000.01', 'percentage' => '2']);
        app(DeleteTransferFeeRule::class)->handle($this->admin(), $legacy);
        $this->assertSame($fixed->id, app(TransferPricing::class)->quote($source, $destination, '100')['fee']->id);
        $this->assertSame($above->id, app(TransferPricing::class)->quote($source, $destination, '1500')['fee']->id);
    }

    public function test_removing_an_old_version_preserves_the_current_version_and_new_versions_can_replace_deleted_ones(): void
    {
        [$source, $destination] = $this->corridor();
        $admin = $this->admin();
        $old = TransferFeeRule::factory()->fixed('4')->create(['maximum_amount' => '1000']);
        $current = TransferFeeRule::factory()->fixed('5')->create(['maximum_amount' => '1000']);
        app(DeleteTransferFeeRule::class)->handle($admin, $old);
        $this->assertSame($current->id, app(TransferPricing::class)->quote($source, $destination, '100')['fee']->id);
        app(DeleteTransferFeeRule::class)->handle($admin, $current);
        $new = TransferFeeRule::factory()->fixed('6')->create(['maximum_amount' => '1000']);
        $this->assertSame($new->id, app(TransferPricing::class)->quote($source, $destination, '100')['fee']->id);
    }

    public function test_removed_fee_cannot_be_restored_or_physically_deleted(): void
    {
        $rule = TransferFeeRule::factory()->create();
        app(DeleteTransferFeeRule::class)->handle($this->admin(), $rule);
        try {
            $rule->refresh()->forceFill(['deleted_at' => null])->save();
            $this->fail('Removal history must be retained.');
        } catch (LogicException) {
            $this->assertNotNull($rule->refresh()->deleted_at);
        }
        $this->expectException(LogicException::class);
        $rule->delete();
    }

    /** @return array{Branch, Branch} */
    private function corridor(): array
    {
        return [Branch::factory()->create(['default_currency' => 'USD']), Branch::factory()->create(['default_currency' => 'USD'])];
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole(Role::Administrator->value);
    }
}
