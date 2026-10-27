<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\InboxNotification;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class UpdateUserTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create()->assignRole(Role::Administrator->value);
        $this->target = User::factory()->for(Branch::factory())->create(['phone' => '+243970000001'])->assignRole(Role::SourceCashier->value);
    }

    public function test_admin_can_edit_user_details_role_and_branch_without_changing_password(): void
    {
        $oldPassword = $this->target->password;
        $destination = Branch::factory()->create();
        $this->actingAs($this->admin)->get(route('users.index'))->assertSee(route('users.edit', $this->target));
        $this->get(route('users.edit', $this->target))->assertOk()->assertSee($this->target->email)->assertSee($this->target->phone)->assertDontSee($oldPassword);
        $this->put(route('users.update', $this->target), $this->input([
            'name' => ' Caissier Dubai ', 'email' => ' Dubai@Example.Test ', 'phone' => '00971 500 000 001',
            'role' => Role::DestinationCashier->value, 'branch_id' => $destination->id,
        ]))->assertRedirect(route('users.index'))->assertSessionHasNoErrors();
        $this->target->refresh();
        $this->assertSame('Caissier Dubai', $this->target->name);
        $this->assertSame('dubai@example.test', $this->target->email);
        $this->assertSame('+971500000001', $this->target->phone);
        $this->assertSame($destination->id, $this->target->branch_id);
        $this->assertSame([Role::DestinationCashier->value], $this->target->getRoleNames()->all());
        $this->assertSame($oldPassword, $this->target->password);
        $this->assertNull($this->target->email_verified_at);
        $this->assertTrue($this->target->can('transfers.pay'));
        $this->assertFalse($this->target->can('transfers.create'));
        $notice = $this->admin->notifications()->sole();
        $this->assertSame('Utilisateur modifié', $notice->data['title']);
        $this->assertStringNotContainsString($oldPassword, json_encode($notice->data));
    }

    public function test_password_can_be_reset_and_new_login_works(): void
    {
        $token = $this->target->remember_token;
        $this->actingAs($this->admin)->put(route('users.update', $this->target), $this->input([
            'password' => 'NewPassword12345', 'password_confirmation' => 'NewPassword12345',
        ]))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('NewPassword12345', $this->target->refresh()->password));
        $this->assertNotSame($token, $this->target->remember_token);
        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => $this->target->email, 'password' => 'NewPassword12345'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->target);
        $this->assertStringNotContainsString('NewPassword12345', json_encode($this->admin->notifications()->sole()->data));
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_changes_are_rejected_without_altering_the_user(array $changes, string $error): void
    {
        $before = $this->target->refresh()->getAttributes();
        $this->actingAs($this->admin)->from(route('users.edit', $this->target))->put(route('users.update', $this->target), $this->input($changes))
            ->assertSessionHasErrors($error)->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation');
        $this->assertSame($before, $this->target->refresh()->getAttributes());
        $this->assertSame([Role::SourceCashier->value], $this->target->getRoleNames()->all());
    }

    public static function invalidInput(): array
    {
        return [
            [['name' => ' '], 'name'], [['email' => 'invalid'], 'email'], [['phone' => '0970000001'], 'phone'],
            [['role' => 'superuser'], 'role'], [['branch_id' => null], 'branch_id'], [['branch_id' => 999999], 'branch_id'],
            [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            [['password' => 'ValidPassword123', 'password_confirmation' => 'other'], 'password'],
            [['password' => str_repeat('é', 36).'1', 'password_confirmation' => str_repeat('é', 36).'1'], 'password'],
        ];
    }

    public function test_duplicates_and_inactive_branches_are_rejected_but_own_login_values_are_allowed(): void
    {
        $this->admin->update(['phone' => '+971500000009']);
        $this->actingAs($this->admin)->put(route('users.update', $this->target), $this->input(['email' => strtoupper($this->admin->email)]))->assertSessionHasErrors('email');
        $this->put(route('users.update', $this->target), $this->input(['phone' => '00971 500 000 009']))->assertSessionHasErrors('phone');
        $inactive = Branch::factory()->create(['status' => RecordStatus::Inactive]);
        $this->put(route('users.update', $this->target), $this->input(['branch_id' => $inactive->id]))->assertSessionHasErrors('branch_id');
        $this->put(route('users.update', $this->target), $this->input())->assertSessionHasNoErrors();
        $this->assertSame(0, $this->admin->notifications()->count());
        $this->put(route('users.update', $this->target), $this->input(['phone' => '']))->assertSessionHasNoErrors();
        $this->assertNull($this->target->refresh()->phone);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_cannot_edit_users_even_with_direct_management_permission(Role $role): void
    {
        $actor = User::factory()->for(Branch::factory())->create()->assignRole($role->value)->givePermissionTo('users.manage');
        $this->actingAs($actor)->get(route('users.edit', $this->target))->assertForbidden();
        $this->put(route('users.update', $this->target), $this->input())->assertForbidden();
        $this->get(route('users.index'))->assertDontSee(route('users.edit', $this->target));
    }

    public static function nonAdminRoles(): array
    {
        return [[Role::SourceCashier], [Role::DestinationCashier], [Role::Manager]];
    }

    public function test_admin_can_change_own_details_but_cannot_remove_own_admin_role(): void
    {
        $this->actingAs($this->admin)->put(route('users.update', $this->admin), [
            'name' => 'Admin modifié', 'email' => $this->admin->email, 'phone' => null, 'role' => 'administrator',
            'password' => 'UpdatedAdmin12345', 'password_confirmation' => 'UpdatedAdmin12345',
        ])->assertSessionHasNoErrors();
        $this->get(route('users.index'))->assertOk()->assertSee('Admin modifié');
        $this->put(route('users.update', $this->admin), $this->input(['email' => $this->admin->email, 'phone' => null]))->assertSessionHasErrors('role');
        $this->assertTrue($this->admin->fresh()->hasRole(Role::Administrator->value));
    }

    public function test_role_change_removes_previous_roles_and_direct_permissions_and_revokes_sessions(): void
    {
        $this->target->assignRole(Role::Manager->value)->givePermissionTo(['branches.view-all', 'users.manage']);
        DB::table('sessions')->insert(['id' => 'old-target-session', 'user_id' => $this->target->id, 'payload' => '', 'last_activity' => time()]);
        config(['session.driver' => 'database']);
        $this->actingAs($this->admin)->put(route('users.update', $this->target), $this->input([
            'role' => Role::DestinationCashier->value, 'permissions' => ['users.manage'], 'roles' => ['administrator'],
            'email_verified_at' => now(),
        ]))->assertSessionHasNoErrors();
        $this->target->refresh();
        $this->assertSame([Role::DestinationCashier->value], $this->target->getRoleNames()->all());
        $this->assertFalse($this->target->can('users.manage'));
        $this->assertFalse($this->target->can('branches.view-all'));
        $this->assertDatabaseMissing('sessions', ['id' => 'old-target-session']);
    }

    public function test_notification_failure_rolls_back_profile_and_role_changes(): void
    {
        $before = $this->target->refresh()->getAttributes();
        InboxNotification::creating(fn () => throw new RuntimeException('Notification failure'));
        try {
            $this->withoutExceptionHandling()->actingAs($this->admin)->put(route('users.update', $this->target), $this->input([
                'name' => 'Changed name', 'role' => Role::DestinationCashier->value,
            ]));
            $this->fail('Expected failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Notification failure', $exception->getMessage());
        }
        $this->assertSame($before, $this->target->refresh()->getAttributes());
        $this->assertSame([Role::SourceCashier->value], $this->target->getRoleNames()->all());
    }

    public function test_guests_cannot_edit_users(): void
    {
        $this->get(route('users.edit', $this->target))->assertRedirect(route('login'));
        $this->put(route('users.update', $this->target), $this->input())->assertRedirect(route('login'));
    }

    /** @return array<string, mixed> */
    private function input(array $changes = []): array
    {
        return ['name' => $this->target->name, 'email' => $this->target->email, 'phone' => $this->target->phone,
            'role' => Role::SourceCashier->value, 'branch_id' => $this->target->branch_id, 'password' => '', 'password_confirmation' => '', ...$changes];
    }
}
