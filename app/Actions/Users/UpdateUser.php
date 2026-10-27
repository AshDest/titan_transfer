<?php

namespace App\Actions\Users;

use App\Enums\RecordStatus;
use App\Enums\Role;
use App\Models\User;
use App\Services\OperationNotifications;
use App\Services\PhoneNumber;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class UpdateUser
{
    /** @param array<string, mixed> $input */
    public function handle(User $actor, User $user, array $input): User
    {
        Gate::forUser($actor)->authorize('update', $user);

        if (is_string($input['name'] ?? null)) {
            $input['name'] = trim($input['name']);
        }
        if (is_string($input['email'] ?? null)) {
            $input['email'] = mb_strtolower(trim($input['email']));
        }
        if (is_string($input['phone'] ?? null)) {
            $input['phone'] = PhoneNumber::normalize($input['phone']) ?: null;
        }

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'phone' => ['nullable', 'string', 'regex:'.PhoneNumber::PATTERN, Rule::unique('users', 'phone')->ignore($user)],
            'role' => ['required', Rule::enum(Role::class), Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'branch_id' => ['required_unless:role,'.Role::Administrator->value, 'nullable', 'integer', Rule::exists('branches', 'id')->where('status', RecordStatus::Active->value)],
            'password' => ['bail', 'nullable', 'string', 'max:72', Password::min(12)->letters()->numbers(), 'confirmed',
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
            'phone.regex' => 'Saisissez un numéro international avec indicatif, par exemple +243970000001.',
            'phone.unique' => 'Ce numéro de téléphone est déjà utilisé.',
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

        return DB::transaction(function () use ($actor, $user, $data): User {
            $locked = User::whereKey([$actor->id, $user->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $locked->get($actor->id);
            $user = $locked->get($user->id);
            Gate::forUser($actor)->authorize('update', $user);
            if ($actor->is($user) && $data['role'] !== Role::Administrator->value) {
                throw ValidationException::withMessages(['role' => 'Vous ne pouvez pas retirer votre propre rôle administrateur.']);
            }
            $roleChanged = $user->getRoleNames()->all() !== [$data['role']];
            $user->fill(['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null]);
            $user->branch_id = $data['branch_id'] ?? null;
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $accessChanged = $roleChanged || $user->isDirty(['branch_id', 'email', 'phone', 'password']);
            $changed = $roleChanged || $user->isDirty();
            if ($accessChanged) {
                $user->remember_token = Str::random(60);
            }
            $user->save();
            if ($roleChanged) {
                $user->syncRoles([$data['role']]);
                $user->syncPermissions([]);
            }
            if ($accessChanged && config('session.driver') === 'database') {
                $sessions = DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id);
                if ($actor->is($user) && request()->hasSession()) {
                    $sessions->where('id', '!=', request()->session()->getId());
                }
                $sessions->delete();
            }
            if ($changed) {
                app(OperationNotifications::class)->record($user, $actor, 'updated');
            }

            return $user->refresh();
        });
    }
}
