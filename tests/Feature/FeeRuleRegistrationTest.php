<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\TransferFeeRule;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FeeRuleRegistrationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('feeConfigurations')]
    public function test_saved_rule_is_visible_in_the_first_page_table_after_redirect(array $changes): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create()->assignRole(Role::Administrator->value);
        TransferFeeRule::factory()->count(20)->create();
        $input = [
            'name' => 'Tarif visible après enregistrement', 'source_branch_id' => '', 'destination_branch_id' => '',
            'source_currency' => 'USD', 'fee_type' => 'fixed', 'fixed_amount' => '5.25', 'active' => '1',
            'minimum_amount' => '0', 'maximum_amount' => '1000', 'effective_from' => now('UTC')->format('Y-m-d\TH:i'),
            'effective_until' => '', ...$changes,
        ];
        $this->actingAs($admin)->from(route('fee-rules.create'))->post(route('fee-rules.store'), $input)
            ->assertRedirect(route('fee-rules.index'))->assertSessionHasNoErrors()->assertSessionHas('status');
        $rule = TransferFeeRule::latest('id')->firstOrFail();
        $this->assertSame($input['name'], $rule->name);
        $this->assertSame($input['fee_type'], $rule->fee_type->value);
        $this->assertSame((bool) $input['active'], $rule->active);
        $this->assertNull($rule->deleted_at);
        $this->assertSame($input['fee_type'] === 'fixed' ? '5.25' : '2.5000', $input['fee_type'] === 'fixed' ? $rule->fixed_amount : $rule->percentage);
        $page = $this->get(route('fee-rules.index'))->assertOk();
        $this->assertSame($rule->id, $page->viewData('records')->first()->id);
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$page->getContent());
        $rows = (new \DOMXPath($document))->query('//table/tbody/tr');
        $this->assertSame(15, $rows->length);
        $this->assertStringContainsString($input['name'], $rows->item(0)->textContent);
        $this->assertStringContainsString($input['fee_type'] === 'fixed' ? '5,25 USD' : '2,5', $rows->item(0)->textContent);
    }

    public static function feeConfigurations(): array
    {
        return [
            'fixed' => [[]],
            'inactive fixed' => [['active' => '0']],
            'future fixed' => [['effective_from' => '2099-01-01T12:00']],
            'percentage' => [['fee_type' => 'percentage', 'percentage' => '2.5', 'minimum_amount' => '1000.01', 'maximum_amount' => '']],
        ];
    }
}
