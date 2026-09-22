<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_displays_the_french_form(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('Connexion')
            ->assertSee('Se connecter')
            ->assertSee('name="_token"', false);
    }

    public function test_guests_must_log_in_to_access_the_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_valid_login_rotates_session_and_redirects_to_dashboard(): void
    {
        $user = $this->administrator();
        $this->get(route('login'));
        $session = session()->getId();

        $this->post(route('login.store'), ['email' => strtoupper($user->email), 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($session, session()->getId());
    }

    public function test_remember_me_sets_the_remember_cookie(): void
    {
        $user = $this->administrator();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => '1'])
            ->assertCookie(Auth::guard('web')->getRecallerName());

        $this->assertNotNull($user->refresh()->remember_token);
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_returns_a_generic_error_and_is_never_flashed(): void
    {
        $user = $this->administrator();

        $this->from(route('login'))->post(route('login.store'), ['email' => $user->email, 'password' => 'secret-wrong-password'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Connexion impossible. Vérifiez vos identifiants ou contactez votre administrateur.'])
            ->assertSessionMissing('_old_input.password');

        $this->assertGuest();
    }

    public function test_missing_credentials_return_french_validation_errors(): void
    {
        $this->post(route('login.store'), [])->assertSessionHasErrors([
            'email' => 'Saisissez votre adresse e-mail professionnelle.',
            'password' => 'Saisissez votre mot de passe.',
        ]);
        $this->assertGuest();
    }

    public function test_unknown_account_uses_the_same_error_as_wrong_password(): void
    {
        $this->post(route('login.store'), ['email' => 'missing@example.test', 'password' => 'incorrect'])
            ->assertSessionHasErrors(['email' => 'Connexion impossible. Vérifiez vos identifiants ou contactez votre administrateur.']);

        $this->assertGuest();
    }

    public function test_login_is_temporarily_blocked_after_five_failed_attempts(): void
    {
        $user = $this->administrator();
        $this->freezeTime();
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'incorrect']);
        }

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Trop de tentatives. Réessayez dans 60 secondes.']);
        $this->assertGuest();

        $this->travel(61)->seconds();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_successful_login_clears_failed_attempts(): void
    {
        $user = $this->administrator();
        $key = 'login:'.hash('sha256', $user->email.'|127.0.0.1');
        RateLimiter::hit($key, 60);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertSame(0, RateLimiter::attempts($key));
    }

    public function test_inactive_branch_cannot_log_in(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $branch = Branch::factory()->create(['status' => RecordStatus::Inactive]);
        $user = User::factory()->for($branch)->create()->assignRole(Role::SourceCashier->value);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_user_without_permissions_cannot_log_in(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_dashboard_refuses_an_authenticated_user_without_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
    }

    public function test_dashboard_refuses_a_branch_deactivated_after_login(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $branch = Branch::factory()->create(['status' => RecordStatus::Inactive]);
        $user = User::factory()->for($branch)->create()->assignRole(Role::SourceCashier->value);

        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
    }

    public function test_cashier_dashboard_only_shows_its_branch_and_customer_count(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $branch = Branch::factory()->create(['name' => 'Visible agency']);
        $other = Branch::factory()->create(['name' => 'Hidden agency']);
        $user = User::factory()->for($branch)->create()->assignRole(Role::SourceCashier->value);
        Customer::factory()->for($branch)->create();
        Customer::factory()->for($other)->count(2)->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee('Visible agency')->assertDontSee('Hidden agency')
            ->assertViewHas('customerCount', 1);
    }

    public function test_destination_cashier_does_not_see_customer_statistics(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->for(Branch::factory())->create()->assignRole(Role::DestinationCashier->value);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertDontSee('Clients enregistrés')->assertDontSee('Bénéficiaires');
    }

    public function test_authenticated_users_are_redirected_away_from_login(): void
    {
        $user = $this->administrator();

        $this->actingAs($user)->get(route('login'))->assertRedirect(route('dashboard'));
    }

    public function test_dashboard_escapes_user_supplied_names(): void
    {
        $user = $this->administrator();
        $user->update(['name' => '<script>alert(1)</script>']);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_logout_invalidates_session_and_rotates_csrf_token(): void
    {
        $user = $this->administrator();
        $this->actingAs($user)->withSession(['private_marker' => 'private'])->get(route('dashboard'));
        $token = session()->token();

        $this->post(route('logout'))->assertRedirect(route('login'))->assertSessionMissing('private_marker');

        $this->assertGuest();
        $this->assertNotSame($token, session()->token());
    }

    public function test_login_requires_csrf_protection_outside_the_test_environment(): void
    {
        $this->app->instance('env', 'local');

        $this->post(route('login.store'), ['email' => 'admin@example.test', 'password' => 'incorrect'])->assertStatus(419);

        $this->assertGuest();
    }

    private function administrator(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->assignRole(Role::Administrator->value);
    }
}
