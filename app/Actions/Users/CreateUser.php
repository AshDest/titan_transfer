<?php

namespace App\Actions\Users;

use App\Enums\RecordStatus;
use App\Enums\Role;
use App\Models\User;
use App\Services\OperationNotifications;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class CreateUser
{
    /** @param array<string, mixed> $input */
    public function handle(User $actor, array $input): User
    {
        Gate::forUser($actor)->authorize('create', User::class);

        if (is_string($input['name'] ?? null)) {
            $input['name'] = trim($input['name']);
        }
        if (is_string($input['email'] ?? null)) {
            $input['email'] = mb_strtolower(trim($input['email']));
        }

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(Role::class), Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'branch_id' => ['required_unless:role,'.Role::Administrator->value, 'nullable', 'integer', Rule::exists('branches', 'id')->where('status', RecordStatus::Active->value)],
            'password' => ['bail', 'required', 'string', 'max:72', Password::min(12)->letters()->numbers(), 'confirmed',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (strlen($value) > 72) {
                        $fail('Le mot de passe est trop long : utilisez au maximum 72 octets.');
                    }
                },
            ],
        ], [
            'required' => 'Ce champ est obligatoire.',
            'string' => 'Saisissez du texte.',
            'max' => 'Ce champ est trop long (maximum :max caractères).',
            'email.email' => 'Saisissez une adresse e-mail valide.',
            'email.unique' => 'Cette adresse e-mail est déjà utilisée.',
            'role.enum' => 'Sélectionnez un rôle valide.',
            'role.exists' => 'Ce rôle n’est pas disponible.',
            'branch_id.required_unless' => 'Une agence est obligatoire pour ce rôle.',
            'branch_id.integer' => 'Sélectionnez une agence valide.',
            'branch_id.exists' => 'Sélectionnez une agence active.',
            'password.min' => 'Utilisez au moins 12 caractères.',
            'password.letters' => 'Le mot de passe doit contenir des lettres.',
            'password.numbers' => 'Le mot de passe doit contenir des chiffres.',
            'password.confirmed' => 'Les mots de passe ne correspondent pas.',
        ])->validate();

        return DB::transaction(function () use ($actor, $data): User {
            $user = new User([
                'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'],
            ]);
            $user->branch_id = $data['branch_id'] ?? null;
            $user->save();
            $user->assignRole($data['role']);
            app(OperationNotifications::class)->record($user, $actor);

            return $user;
        });
    }
}
