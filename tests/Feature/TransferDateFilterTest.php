<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TransferStatus;
use App\Models\Branch;
use App\Models\Transfer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TransferDateFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_interval_includes_whole_local_days_and_filters_totals_across_pages_with_agency_access(): void
    {
        $branch = Branch::factory()->create(['timezone' => 'Africa/Lubumbashi']);
        $user = User::factory()->for($branch)->create()->assignRole(Role::Manager->value);
        $factory = Transfer::factory()->state(['source_branch_id' => $branch->id]);
        $factory->count(15)->sequence(fn ($sequence) => ['reference' => 'TT-PERIOD-'.$sequence->index])->create(['created_at' => '2026-08-31 22:00:00']);
        $factory->create(['reference' => 'TT-PERIOD-END', 'created_at' => '2026-09-30 21:59:59']);
        $factory->create(['reference' => 'TT-PERIOD-BEFORE', 'created_at' => '2026-08-31 21:59:59']);
        $factory->create(['reference' => 'TT-PERIOD-AFTER', 'created_at' => '2026-09-30 22:00:00']);
        $factory->create(['reference' => 'TT-PERIOD-APPROVED', 'created_at' => '2026-09-15 12:00:00', 'status' => TransferStatus::Approved]);
        $factory->create(['reference' => 'TT-OTHER-REFERENCE', 'created_at' => '2026-09-15 12:00:00']);
        Transfer::factory()->create(['reference' => 'TT-PERIOD-HIDDEN', 'created_at' => '2026-09-15 12:00:00']);
        $filters = ['q' => 'TT-PERIOD', 'status' => 'PENDING', 'date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'page' => 2];

        $response = $this->actingAs($user)->get(route('transfers.index', $filters));

        $response->assertOk()->assertSee('Africa/Lubumbashi')->assertSee('01/09/2026 00:00')
            ->assertSee('value="2026-09-01"', false)->assertSee('value="2026-09-30"', false)
            ->assertSee('Réinitialiser');
        $records = $response->viewData('records');
        $this->assertSame(16, $records->total());
        $this->assertCount(1, $records);
        $this->assertSame('1600.00', $response->viewData('totals')['USD']['received']);
        $this->assertSame('48.00', $response->viewData('totals')['USD']['fees']);
        parse_str(parse_url($records->previousPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('2026-09-01', $query['date_from']);
        $this->assertSame('2026-09-30', $query['date_to']);
        $this->assertSame('TT-PERIOD', $query['q']);
    }

    #[DataProvider('openIntervals')]
    public function test_one_date_or_same_day_interval_is_supported(array $filters, array $references, string $total): void
    {
        $user = User::factory()->create(['branch_id' => null])->assignRole(Role::Administrator->value);
        Transfer::factory()->create(['reference' => 'TT-BEFORE', 'created_at' => '2026-09-14 23:59:59']);
        Transfer::factory()->create(['reference' => 'TT-DURING', 'created_at' => '2026-09-15 23:59:59']);
        Transfer::factory()->create(['reference' => 'TT-AFTER', 'created_at' => '2026-09-16 00:00:00']);

        $response = $this->actingAs($user)->get(route('transfers.index', $filters));

        $response->assertOk()->assertSee('UTC');
        $this->assertSame($references, $response->viewData('records')->pluck('reference')->all());
        $this->assertSame($total, $response->viewData('totals')['USD']['received']);
    }

    public static function openIntervals(): array
    {
        return [
            'start only' => [['date_from' => '2026-09-15'], ['TT-AFTER', 'TT-DURING'], '200.00'],
            'end only' => [['date_to' => '2026-09-15'], ['TT-DURING', 'TT-BEFORE'], '200.00'],
            'same day' => [['date_from' => '2026-09-15', 'date_to' => '2026-09-15'], ['TT-DURING'], '100.00'],
        ];
    }

    #[DataProvider('invalidIntervals')]
    public function test_invalid_intervals_show_validation_errors(array $filters, string $field, string $message): void
    {
        $user = User::factory()->create()->assignRole(Role::Administrator->value);

        $response = $this->actingAs($user)->followingRedirects()->from(route('transfers.index'))->get(route('transfers.index', $filters));

        $response->assertSee($message)->assertSee($field.'-error');
    }

    public static function invalidIntervals(): array
    {
        return [
            'reversed dates' => [['date_from' => '2026-09-30', 'date_to' => '2026-09-01'], 'date_to', 'La date de fin doit être égale ou postérieure à la date de début.'],
            'impossible date' => [['date_from' => '2026-02-30'], 'date_from', 'La date de début doit être une date valide au format AAAA-MM-JJ.'],
            'wrong format' => [['date_to' => '30/09/2026'], 'date_to', 'La date de fin doit être une date valide au format AAAA-MM-JJ.'],
            'array date' => [['date_from' => ['2026-09-01']], 'date_from', 'La date de début doit être une date valide.'],
        ];
    }

    public function test_period_without_transfers_has_empty_totals(): void
    {
        $user = User::factory()->create()->assignRole(Role::Administrator->value);
        Transfer::factory()->create(['created_at' => '2026-09-01 00:00:00']);

        $response = $this->actingAs($user)->get(route('transfers.index', ['date_from' => '2026-10-01', 'date_to' => '2026-10-31']));

        $response->assertSee('Aucun transfert pour ces filtres. Total : 0,00.');
        $this->assertSame([], $response->viewData('totals'));
    }
}
