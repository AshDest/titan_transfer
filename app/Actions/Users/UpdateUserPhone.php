<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Services\OperationNotifications;
use App\Services\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class UpdateUserPhone
{
    /** @param array<string, mixed> $input */
    public function handle(User $actor, User $user, array $input): User
    {
        Gate::forUser($actor)->authorize('updatePhone', $user);

        if (is_string($input['phone'] ?? null)) {
            $input['phone'] = PhoneNumber::normalize($input['phone']) ?: null;
        }
        $data = Validator::make($input, [
            'phone' => ['present', 'nullable', 'string', 'regex:'.PhoneNumber::PATTERN, Rule::unique('users', 'phone')->ignore($user)],
        ], [
            'phone.present' => 'Renseignez le numéro de téléphone ou laissez le champ vide pour le retirer.',
            'phone.string' => 'Saisissez un numéro de téléphone valide.',
            'phone.regex' => 'Saisissez un numéro international avec indicatif, par exemple +243970000001.',
            'phone.unique' => 'Ce numéro de téléphone est déjà utilisé.',
        ])->validate();

        return DB::transaction(function () use ($actor, $user, $data): User {
            $user->phone = $data['phone'];
            if ($user->isDirty('phone')) {
                $user->save();
                app(OperationNotifications::class)->record($user, $actor, 'phone_updated');
            }

            return $user;
        });
    }
}
