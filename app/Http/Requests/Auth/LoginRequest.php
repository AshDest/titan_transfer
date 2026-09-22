<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
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
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Saisissez votre adresse e-mail professionnelle.',
            'email.email' => 'Saisissez une adresse e-mail valide.',
            'email.max' => 'Votre adresse e-mail est trop longue.',
            'password.required' => 'Saisissez votre mot de passe.',
            'password.max' => 'Votre mot de passe est trop long.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function authenticate(): void
    {
        $key = 'login:'.hash('sha256', $this->string('email').'|'.$this->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            event(new Lockout($this));

            throw ValidationException::withMessages([
                'email' => 'Trop de tentatives. Réessayez dans '.RateLimiter::availableIn($key).' secondes.',
            ]);
        }

        if (! Auth::guard('web')->attemptWhen(
            $this->only('email', 'password'),
            fn (User $user): bool => $user->canAccessApplication(),
            $this->boolean('remember'),
        )) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => 'Connexion impossible. Vérifiez vos identifiants ou contactez votre administrateur.',
            ]);
        }

        RateLimiter::clear($key);
    }
}
