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
use Spatie\Permission\Models\Role as PermissionRole;
use Tests\TestCase;

class CreateUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_administrator_can_find_the_form_and_select_roles_and_active_agencies(): void
    {
        $active = Branch::factory()->create();
        $inactive = Branch::factory()->create(['status' => RecordStatus::Inactive]);
        $this->actingAs($this->administrator())->get(route('users.index'))
            ->assertOk()->assertSee(route('users.create'))->assertSee('Nouvel utilisateur');

        $response = $this->get(route('users.create'))->assertOk()
            ->assertSee(route('users.store'))->assertSee($active->name)->assertDontSee($inactive->name);
        foreach (Role::cases() as $role) {
            $response->assertSee($role->label());
        }
    }

    #[DataProvider('roles')]
    public function test_administrator_can_create_each_role_and_the_new_account_can_log_in(Role $role): void
    {
        $administrator = $this->administrator();
        $branch = Branch::factory()->create();
        $payload = $this->payload([
            'role' => $role->value,
            'branch_id' => $role === Role::Administrator ? null : $branch->id,
        ]);

        $this->actingAs($administrator)->post(route('users.store'), $payload)
            ->assertRedirect(route('users.index'))->assertSessionHasNoErrors()->assertSessionHas('status');

        $user = User::where('email', 'new.user@example.test')->sole();
        $this->assertSame('Nouvel Utilisateur', $user->name);
        $this->assertSame($payload['branch_id'], $user->branch_id);
        $this->assertSame([$role->value], $user->getRoleNames()->all());
        $this->assertTrue(Hash::check($payload['password'], $user->password));
        $this->assertAuthenticatedAs($administrator);
        $this->get(route('users.index'))->assertSee($user->email)->assertSee($role->label());

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => $payload['password']])
            ->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->get(route('dashboard'))->assertOk();
    }

    public static function roles(): array
    {
        return array_map(fn (Role $role): array => [$role], Role::cases());
    }

    #[DataProvider('branchRoles')]
    public function test_branch_staff_cannot_create_users_even_with_a_direct_users_permission(Role $role): void
    {
        $actor = User::factory()->for(Branch::factory())->create()->assignRole($role->value);
        $actor->givePermissionTo('users.manage');
        $this->actingAs($actor)->get(route('users.create'))->assertForbidden();
        $this->post(route('users.store'), $this->payload())->assertForbidden();
        $this->get(route('users.index'))->assertDontSee(route('users.create'));
        $this->assertDatabaseCount('users', 1);
    }

    #[DataProvider('branchRoles')]
    public function test_an_agency_is_required_for_branch_roles(Role $role): void
    {
        $this->actingAs($this->administrator())->post(route('users.store'), $this->payload(['role' => $role->value]))
            ->assertSessionHasErrors('branch_id');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('notifications', 0);
    }

    public static function branchRoles(): array
    {
        return [[Role::SourceCashier], [Role::DestinationCashier], [Role::Manager]];
    }

    public function test_guests_cannot_access_or_submit_the_form(): void
    {
        $this->get(route('users.create'))->assertRedirect(route('login'));
        $this->post(route('users.store'), $this->payload())->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_details_are_rejected_without_creating_a_user(array $overrides, string $field): void
    {
        $this->actingAs($this->administrator())->from(route('users.create'))
            ->post(route('users.store'), $this->payload($overrides))
            ->assertRedirect(route('users.create'))->assertSessionHasErrors($field)
            ->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('notifications', 0);
    }

    public static function invalidInput(): array
    {
        return [
            'empty name' => [['name' => ' '], 'name'],
            'invalid email' => [['email' => 'not-an-email'], 'email'],
            'unknown role' => [['role' => 'superuser'], 'role'],
            'missing role' => [['role' => ''], 'role'],
            'invalid branch' => [['branch_id' => 999999], 'branch_id'],
            'short password' => [['password' => 'Test123', 'password_confirmation' => 'Test123'], 'password'],
            'no numbers' => [['password' => 'PasswordOnlyLetters', 'password_confirmation' => 'PasswordOnlyLetters'], 'password'],
            'no letters' => [['password' => '123456789012', 'password_confirmation' => '123456789012'], 'password'],
            'unconfirmed' => [['password_confirmation' => 'different'], 'password'],
            'too many bytes' => [['password' => str_repeat('é', 36).'1', 'password_confirmation' => str_repeat('é', 36).'1'], 'password'],
        ];
    }

    public function test_inactive_agency_is_rejected(): void
    {
        $branch = Branch::factory()->create(['status' => RecordStatus::Inactive]);
        $this->actingAs($this->administrator())->post(route('users.store'), $this->payload(['branch_id' => $branch->id]))
            ->assertSessionHasErrors('branch_id');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_duplicate_email_is_rejected_after_normalization(): void
    {
        $administrator = $this->administrator();
        $this->actingAs($administrator)->post(route('users.store'), $this->payload(['email' => ' '.strtoupper($administrator->email).' ']))
            ->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_missing_seeded_role_returns_validation_error_instead_of_creating_an_unusable_account(): void
    {
        PermissionRole::where('name', Role::Manager->value)->delete();
        $branch = Branch::factory()->create();
        $this->actingAs($this->administrator())->get(route('users.create'))->assertDontSee(Role::Manager->label());
        $this->post(route('users.store'), $this->payload(['role' => Role::Manager->value, 'branch_id' => $branch->id]))
            ->assertSessionHasErrors('role');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_extra_fields_cannot_grant_permissions_or_add_another_role(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAs($this->administrator())->post(route('users.store'), $this->payload([
            'role' => Role::SourceCashier->value, 'branch_id' => $branch->id,
            'permissions' => ['users.manage'], 'roles' => ['administrator'],
            'email_verified_at' => now()->toDateTimeString(),
        ]))->assertSessionHasNoErrors();

        $user = User::where('email', 'new.user@example.test')->sole();
        $this->assertFalse($user->can('users.manage'));
        $this->assertSame([Role::SourceCashier->value], $user->getRoleNames()->all());
        $this->assertNull($user->email_verified_at);
    }

    public function test_validation_errors_are_visible_and_password_fields_stay_empty(): void
    {
        $this->followingRedirects()->actingAs($this->administrator())->from(route('users.create'))
            ->post(route('users.store'), $this->payload(['password_confirmation' => 'different']))
            ->assertOk()->assertSee('Nouvel Utilisateur')
            ->assertSee('Les mots de passe ne correspondent pas.')
            ->assertDontSee('TestPassword12345');
    }

    public function test_creation_notifies_administrators_without_exposing_passwords_and_links_to_the_user(): void
    {
        $administrator = $this->administrator();
        $email = 'recipient@'.str_repeat('a', 50).'.'.str_repeat('b', 50).'.test';
        $cashier = User::factory()->for(Branch::factory())->create()->assignRole(Role::SourceCashier->value);
        $this->actingAs($administrator)->post(route('users.store'), $this->payload([
            'email' => $email,
            'role' => Role::DestinationCashier->value, 'branch_id' => $cashier->branch_id,
        ]))->assertSessionHasNoErrors();

        $user = User::where('email', $email)->sole();
        $notification = $administrator->notifications()->sole();
        $this->assertSame('Nouvel utilisateur', $notification->data['title']);
        $this->assertStringNotContainsString('TestPassword12345', json_encode($notification->data));
        $this->assertStringNotContainsString($user->password, json_encode($notification->data));
        $this->assertSame(0, $cashier->notifications()->count());
        $this->assertSame(0, $user->notifications()->count());
        $this->get(route('notifications.index'))->assertOk()->assertSee('Nouvel utilisateur');
        $this->post(route('notifications.open', $notification))
            ->assertRedirect(route('users.index', ['q' => $user->email]));
        $this->get(route('users.index', ['q' => $user->email]))->assertOk()->assertSee($user->email);
        $this->assertNotNull($notification->refresh()->read_at);
    }

    public function test_user_and_role_are_rolled_back_when_notification_storage_fails(): void
    {
        $administrator = $this->administrator();
        $assignmentCount = DB::table('model_has_roles')->count();
        InboxNotification::creating(function (): void {
            throw new RuntimeException('Notification unavailable');
        });

        try {
            $this->withoutExceptionHandling()->actingAs($administrator)->post(route('users.store'), $this->payload());
            $this->fail('Expected notification storage to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Notification unavailable', $exception->getMessage());
        } finally {
            InboxNotification::flushEventListeners();
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('model_has_roles', $assignmentCount);
        $this->assertDatabaseCount('notifications', 0);
    }

    private function administrator(): User
    {
        return User::factory()->create()->assignRole(Role::Administrator->value);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => ' Nouvel Utilisateur ', 'email' => ' New.User@Example.Test ',
            'role' => Role::Administrator->value, 'branch_id' => null,
            'password' => 'TestPassword12345', 'password_confirmation' => 'TestPassword12345',
        ], $overrides);
    }
}
