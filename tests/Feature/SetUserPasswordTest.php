<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SetUserPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_command_sets_a_hash_and_revokes_old_sessions(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $previousToken = $user->remember_token;
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->artisan('titan:password', ['email' => $user->email])
            ->expectsQuestion('Nouveau mot de passe (12 caractères minimum)', 'Local-Test-123456')
            ->expectsQuestion('Confirmez le mot de passe', 'Local-Test-123456')
            ->expectsOutput('Mot de passe défini. Vous pouvez maintenant vous connecter.')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('Local-Test-123456', $user->refresh()->password));
        $this->assertNotSame($previousToken, $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
    }

    public function test_mismatched_confirmation_does_not_change_password(): void
    {
        $user = User::factory()->create();
        $hash = $user->password;

        $this->artisan('titan:password', ['email' => $user->email])
            ->expectsQuestion('Nouveau mot de passe (12 caractères minimum)', 'Local-Test-123456')
            ->expectsQuestion('Confirmez le mot de passe', 'Another-Test-123456')
            ->expectsOutput('Les mots de passe ne correspondent pas.')
            ->assertFailed();

        $this->assertSame($hash, $user->refresh()->password);
    }

    public function test_unknown_account_is_not_created(): void
    {
        $this->artisan('titan:password', ['email' => 'missing@example.test'])
            ->expectsOutput('Compte introuvable.')->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
