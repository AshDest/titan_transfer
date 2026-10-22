<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    #[DataProvider('phoneFormats')]
    public function test_phone_login_normalizes_format_and_preserves_session_security(string $phone): void
    {
        $user = $this->administrator(['phone' => '+243970000001']);
        $this->get(route('login'))->assertSee('Adresse e-mail ou téléphone')->assertSee('type="text"', false);
        $session = session()->getId();

        $this->post(route('login.store'), ['login' => $phone, 'password' => 'password', 'remember' => '1'])
            ->assertRedirect(route('dashboard'))->assertCookie(Auth::guard('web')->getRecallerName());

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($session, session()->getId());
    }

    public static function phoneFormats(): array
    {
        return [['+243970000001'], [' +243 (970) 000-001 '], ['00243 970 000 001']];
    }

    public function test_email_and_phone_share_the_same_failed_attempt_limit(): void
    {
        $this->freezeTime();
        $user = $this->administrator(['phone' => '+243970000001']);
        foreach ([$user->email, $user->phone, strtoupper($user->email), '00243 970 000 001', '+243 (970) 000-001'] as $login) {
            $this->post(route('login.store'), ['login' => $login, 'password' => 'incorrect'])->assertSessionHasErrors('login');
        }

        $this->post(route('login.store'), ['login' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['login' => 'Trop de tentatives. Réessayez dans 60 secondes.']);
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->post(route('login.store'), ['login' => $user->phone, 'password' => 'password'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_unknown_phone_and_wrong_password_have_the_same_error(): void
    {
        $this->administrator(['phone' => '+243970000001']);
        foreach (['+243970000001', '+243970000002'] as $phone) {
            $this->from(route('login'))->post(route('login.store'), ['login' => $phone, 'password' => 'incorrect'])
                ->assertRedirect(route('login'))
                ->assertSessionHasErrors(['login' => 'Connexion impossible. Vérifiez vos identifiants ou contactez votre administrateur.'])
                ->assertSessionMissing('_old_input.password');
            $this->assertGuest();
        }
    }

    public function test_inactive_branch_and_unprivileged_accounts_cannot_log_in_by_phone(): void
    {
        $branch = Branch::factory()->create(['status' => RecordStatus::Inactive]);
        User::factory()->for($branch)->create(['phone' => '+243970000001'])->assignRole(Role::SourceCashier->value);
        User::factory()->create(['phone' => '+243970000002']);
        foreach (['+243970000001', '+243970000002'] as $phone) {
            $this->post(route('login.store'), ['login' => $phone, 'password' => 'password'])->assertSessionHasErrors('login');
            $this->assertGuest();
        }
    }

    public function test_administrator_can_create_a_user_with_a_normalized_phone_and_log_in(): void
    {
        $this->actingAs($this->administrator())->post(route('users.store'), $this->newUserInput(['phone' => '00243 (970) 000-001']))
            ->assertRedirect(route('users.index'))->assertSessionHasNoErrors();
        $user = User::where('email', 'phone@example.test')->sole();
        $this->assertSame('+243970000001', $user->phone);
        $this->get(route('users.index', ['q' => '00243 970 000 001']))->assertSee($user->email)->assertSee($user->phone);
        $this->post(route('logout'));

        $this->post(route('login.store'), ['login' => '+243970000001', 'password' => 'TestPassword12345'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_duplicate_phone_is_refused_on_creation_and_update_after_normalization(): void
    {
        $administrator = $this->administrator(['phone' => '+243970000001']);
        $target = User::factory()->create();
        $this->actingAs($administrator)->post(route('users.store'), $this->newUserInput(['phone' => '00243 970 000 001']))
            ->assertSessionHasErrors(['phone' => 'Ce numéro de téléphone est déjà utilisé.']);
        $this->patch(route('users.phone.update', $target), ['phone' => '+243 (970) 000-001'])
            ->assertSessionHasErrors('phone');
        $this->assertNull($target->refresh()->phone);
        $this->assertDatabaseCount('users', 2);
    }

    #[DataProvider('invalidPhones')]
    public function test_invalid_phone_numbers_cannot_be_assigned(mixed $phone): void
    {
        $administrator = $this->administrator();
        $target = User::factory()->create();
        $this->actingAs($administrator)->post(route('users.store'), $this->newUserInput(['phone' => $phone]))->assertSessionHasErrors('phone');
        $this->patch(route('users.phone.update', $target), ['phone' => $phone])->assertSessionHasErrors('phone');
        $this->assertNull($target->refresh()->phone);
        $this->assertDatabaseCount('users', 2);
    }

    public static function invalidPhones(): array
    {
        return [['0970000001'], ['+0123456789'], ['+24312'], ['+1234567890123456'], ['+243970000001ext2'], [['+243970000001']]];
    }

    public function test_admin_can_set_change_keep_and_remove_an_existing_users_phone_without_changing_other_fields(): void
    {
        $administrator = $this->administrator();
        $target = User::factory()->for(Branch::factory())->create(['phone' => '+243970000009'])->assignRole(Role::SourceCashier->value);
        $original = $target->only(['name', 'email', 'password', 'branch_id']);
        $this->actingAs($administrator)->get(route('users.index'))->assertSee(route('users.phone.edit', $target));
        $this->get(route('users.phone.edit', $target))->assertOk()->assertSee($target->phone);

        $this->patch(route('users.phone.update', $target), [
            'phone' => '+243 970 000 001', 'email' => 'injected@example.test', 'role' => 'administrator', 'password' => 'injected',
        ])->assertRedirect(route('users.index'))->assertSessionHasNoErrors();
        $this->assertSame('+243970000001', $target->refresh()->phone);
        $this->assertSame($original, $target->only(array_keys($original)));
        $this->assertFalse($target->hasRole(Role::Administrator->value));
        $this->assertSame('phone_updated', $administrator->notifications()->sole()->data['event']);
        $this->patch(route('users.phone.update', $target), ['phone' => '+243970000001'])->assertSessionHasNoErrors();
        $this->assertSame(1, $administrator->notifications()->count());

        $this->post(route('logout'));
        $this->post(route('login.store'), ['login' => '+243970000009', 'password' => 'password'])->assertSessionHasErrors('login');
        $this->post(route('login.store'), ['login' => '+243970000001', 'password' => 'password'])->assertRedirect(route('dashboard'));
        $this->post(route('logout'));
        $this->actingAs($administrator)->patch(route('users.phone.update', $target), ['phone' => ''])->assertSessionHasNoErrors();
        $this->assertNull($target->refresh()->phone);
        $this->post(route('logout'));
        $this->post(route('login.store'), ['login' => '+243970000001', 'password' => 'password'])->assertSessionHasErrors('login');
        $this->post(route('login.store'), ['login' => $target->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($target);
    }

    #[DataProvider('restrictedRoles')]
    public function test_branch_staff_cannot_assign_login_numbers(Role $role): void
    {
        $actor = User::factory()->for(Branch::factory())->create()->assignRole($role->value)->givePermissionTo('users.manage');
        $target = User::factory()->create();
        $this->actingAs($actor)->get(route('users.phone.edit', $target))->assertForbidden();
        $this->patch(route('users.phone.update', $target), ['phone' => '+243970000001'])->assertForbidden();
        $this->assertNull($target->refresh()->phone);
    }

    public static function restrictedRoles(): array
    {
        return [[Role::SourceCashier], [Role::DestinationCashier], [Role::Manager]];
    }

    public function test_guests_cannot_manage_user_phones(): void
    {
        $user = User::factory()->create();
        $this->get(route('users.phone.edit', $user))->assertRedirect(route('login'));
        $this->patch(route('users.phone.update', $user), ['phone' => '+243970000001'])->assertRedirect(route('login'));
        $this->assertNull($user->refresh()->phone);
    }

    public function test_malformed_login_is_rejected_without_server_error(): void
    {
        $this->followingRedirects()->from(route('login'))->post(route('login.store'), ['login' => ['invalid'], 'password' => 'secret'])
            ->assertOk()->assertSee('Saisissez une adresse e-mail ou un numéro de téléphone valide.')->assertDontSee('value="secret"', false);
        $this->assertGuest();
    }

    private function administrator(array $attributes = []): User
    {
        return User::factory()->create($attributes)->assignRole(Role::Administrator->value);
    }

    private function newUserInput(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Phone User', 'email' => 'phone@example.test', 'role' => Role::Administrator->value,
            'password' => 'TestPassword12345', 'password_confirmation' => 'TestPassword12345',
        ], $overrides);
    }
}
