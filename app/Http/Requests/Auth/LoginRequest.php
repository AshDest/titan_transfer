<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\PhoneNumber;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'login.required' => 'Saisissez votre adresse e-mail ou votre numéro de téléphone.',
            'login.string' => 'Saisissez une adresse e-mail ou un numéro de téléphone valide.',
            'login.max' => 'Votre identifiant est trop long.',
            'password.required' => 'Saisissez votre mot de passe.',
            'password.max' => 'Votre mot de passe est trop long.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $login = $this->input('login', $this->input('email'));
        if (is_string($login)) {
            $login = str_contains($login, '@') ? mb_strtolower(trim($login)) : PhoneNumber::normalize($login);
        }
        $this->merge(['login' => $login]);
    }

    public function authenticate(): void
    {
        $login = $this->string('login')->toString();
        $field = str_contains($login, '@') ? 'email' : 'phone';
        $userId = User::where($field, $login)->value('id');
        $key = 'login:'.hash('sha256', ($userId === null ? $login : 'user:'.$userId).'|'.$this->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            event(new Lockout($this));

            throw ValidationException::withMessages([
                'login' => 'Trop de tentatives. Réessayez dans '.RateLimiter::availableIn($key).' secondes.',
            ]);
        }

        if (! Auth::guard('web')->attemptWhen(
            [$field => $login, 'password' => $this->input('password')],
            fn (User $user): bool => $user->canAccessApplication(),
            $this->boolean('remember'),
        )) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'login' => 'Connexion impossible. Vérifiez vos identifiants ou contactez votre administrateur.',
            ]);
        }

        RateLimiter::clear($key);
    }
}
