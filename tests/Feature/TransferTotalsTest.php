<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TransferStatus;
use App\Models\Branch;
use App\Models\Transfer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferTotalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_totals_include_all_filtered_pages_and_keep_currencies_separate(): void
    {
        $admin = User::factory()->create()->assignRole(Role::Administrator->value);
        $source = Branch::factory()->create();
        $destination = Branch::factory()->create();
        Transfer::factory()->count(16)->sequence(fn ($sequence) => ['reference' => 'TT-SELECTED-'.$sequence->index])->create([
            'source_branch_id' => $source->id, 'destination_branch_id' => $destination->id,
            'status' => TransferStatus::Pending,
        ]);
        Transfer::factory()->create(['reference' => 'TT-SELECTED-APPROVED', 'status' => TransferStatus::Approved]);
        Transfer::factory()->create(['reference' => 'TT-OTHER', 'status' => TransferStatus::Pending]);

        $response = $this->actingAs($admin)->get(route('transfers.index', ['q' => 'TT-SELECTED', 'status' => 'PENDING', 'page' => 2]));

        $response->assertOk()->assertSee('Totaux des transferts filtrés')->assertSee('16 transfert(s)')->assertSee('1 600,00')->assertSee('5 695,84');
        $this->assertCount(1, $response->viewData('records'));
        $this->assertSame([
            'AED' => ['received' => '0.00', 'fees' => '0.00', 'net' => '0.00', 'payout' => '5695.84'],
            'USD' => ['received' => '1600.00', 'fees' => '48.00', 'net' => '1552.00', 'payout' => '0.00'],
        ], $response->viewData('totals'));
    }

    public function test_totals_only_include_transfers_visible_to_the_cashier(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->for($branch)->create()->assignRole(Role::SourceCashier->value);
        Transfer::factory()->create(['source_branch_id' => $branch->id]);
        Transfer::factory()->create(['destination_branch_id' => $branch->id, 'status' => TransferStatus::Approved]);
        Transfer::factory()->create(['destination_branch_id' => $branch->id, 'status' => TransferStatus::Pending]);
        Transfer::factory()->create(['destination_branch_id' => $branch->id, 'status' => TransferStatus::Rejected]);
        Transfer::factory()->create(['status' => TransferStatus::Approved]);

        $response = $this->actingAs($cashier)->get(route('transfers.index'));

        $response->assertOk();
        $this->assertSame(2, $response->viewData('records')->total());
        $this->assertSame('200.00', $response->viewData('totals')['USD']['received']);
        $this->assertSame('711.98', $response->viewData('totals')['AED']['payout']);
    }

    public function test_totals_preserve_cents_for_large_amounts_and_same_currency_transfers(): void
    {
        $admin = User::factory()->create()->assignRole(Role::Administrator->value);
        Transfer::factory()->count(2)->create([
            'source_currency' => 'USD', 'destination_currency' => 'USD', 'amount_received' => '9999999999999999.99',
            'fee_amount' => '0.01', 'net_amount' => '9999999999999999.98', 'payout_amount' => '9999999999999999.98',
        ]);

        $response = $this->actingAs($admin)->get(route('transfers.index'));

        $response->assertOk();
        $this->assertSame([
            'USD' => ['received' => '19999999999999999.98', 'fees' => '0.02', 'net' => '19999999999999999.96', 'payout' => '19999999999999999.96'],
        ], $response->viewData('totals'));
    }

    public function test_empty_filter_has_no_currency_totals(): void
    {
        $admin = User::factory()->create()->assignRole(Role::Administrator->value);
        Transfer::factory()->create(['reference' => 'TT-EXISTING']);

        $response = $this->actingAs($admin)->get(route('transfers.index', ['q' => 'NO-MATCH']));

        $response->assertSee('Aucun transfert pour ces filtres. Total : 0,00.');
        $this->assertSame([], $response->viewData('totals'));
    }

    public function test_guests_and_users_without_transfer_access_cannot_read_totals(): void
    {
        $this->get(route('transfers.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('transfers.index'))->assertForbidden();
    }
}
