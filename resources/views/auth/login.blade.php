<x-layouts.base title="Connexion">
    <main class="login-centered">
        <section class="login-card" aria-labelledby="login-title">
            <x-brand />
            <h1 id="login-title">Connexion</h1>
                @if (session('status'))
                    <div class="notice notice-success" role="status"><x-icon name="check" /><span>{{ session('status') }}</span></div>
                @endif

                <form method="POST" action="{{ route('login.store') }}" class="login-form" data-submit-form>
                    @csrf
                    <div class="field">
                        <label for="login">Adresse e-mail ou téléphone</label>
                        <div class="input-wrap @error('login') is-invalid @enderror">
                            <x-icon name="mail" />
                            <input id="login" name="login" type="text" value="{{ is_string(old('login', old('email'))) ? old('login', old('email')) : '' }}" placeholder="vous@entreprise.com ou +243…" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="255" required autofocus @error('login') aria-invalid="true" aria-describedby="login-error" @enderror>
                        </div>
                        @error('login')<p class="field-error" id="login-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="password">Mot de passe</label>
                        <div class="input-wrap @error('password') is-invalid @enderror">
                            <x-icon name="lock" />
                            <input id="password" name="password" type="password" placeholder="Votre mot de passe" autocomplete="current-password" maxlength="1024" required @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                            <button class="password-toggle" type="button" data-password-toggle aria-label="Afficher le mot de passe" aria-controls="password" aria-pressed="false"><x-icon name="eye" /></button>
                        </div>
                        @error('password')<p class="field-error" id="password-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="form-options">
                        <label class="checkbox-label"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> Rester connecté</label>
                    </div>
                    <button class="primary-button" type="submit" data-submit-button><span data-button-label>Se connecter</span><x-icon name="arrow" /><span class="button-spinner" hidden></span></button>
                </form>

        </section>
    </main>
</x-layouts.base>
