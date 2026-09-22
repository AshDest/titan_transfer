<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class SetUserPassword extends Command
{
    protected $signature = 'titan:password {email : Adresse e-mail du compte existant}';

    protected $description = 'Définir un mot de passe sans le passer dans la ligne de commande';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Cette commande nécessite une saisie interactive et masquée.');

            return self::FAILURE;
        }

        $user = User::where('email', mb_strtolower(trim($this->argument('email'))))->first();

        if (! $user) {
            $this->error('Compte introuvable.');

            return self::FAILURE;
        }

        $password = $this->secret('Nouveau mot de passe (12 caractères minimum)');
        $confirmation = $this->secret('Confirmez le mot de passe');
        $validator = Validator::make([
            'password' => $password, 'password_confirmation' => $confirmation,
        ], ['password' => ['required', 'string', 'max:72', Password::min(12)->letters()->numbers(), 'confirmed']], [
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => 'Utilisez au moins 12 caractères.',
            'password.max' => 'Utilisez au maximum 72 caractères.',
            'password.confirmed' => 'Les mots de passe ne correspondent pas.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $password): void {
            $user->password = $password;
            $user->remember_token = Str::random(60);
            $user->save();

            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)->delete();
            }
        });

        $this->info('Mot de passe défini. Vous pouvez maintenant vous connecter.');

        return self::SUCCESS;
    }
}
