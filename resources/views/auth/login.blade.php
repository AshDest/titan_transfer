<x-layouts.base title="Connexion">
    <main class="login-page">
        <section class="login-story" aria-labelledby="story-title">
            <x-brand />
            <div class="story-content">
                <span class="eyebrow"><span class="status-dot"></span> AU-DELÀ DES FRONTIÈRES</span>
                <h1 id="story-title">La confiance,<br>à chaque<br><em>transfert.</em></h1>
                <p>Un espace commun pour vos équipes.<br>Un lien essentiel entre vos agences.</p>
                <div class="journey" aria-hidden="true">
                    <div class="journey-orbit orbit-one"></div><div class="journey-orbit orbit-two"></div>
                    <div class="journey-point origin"><span><x-icon name="branch" /></span><small>VOTRE AGENCE</small></div>
                    <div class="journey-track"><i></i><x-icon name="arrow" /></div>
                    <div class="journey-point destination"><span><x-icon name="users" /></span><small>LEURS PROJETS</small></div>
                    <div class="journey-note"><span class="status-dot"></span> Plus proches, même à distance.</div>
                </div>
            </div>
            <div class="story-footer"><span>Des liens forts. Des horizons ouverts.</span><span>01 — TITAN</span></div>
        </section>

        <section class="login-panel" aria-labelledby="login-title">
            <div class="login-topline"><span><x-icon name="lock" /> ESPACE COLLABORATEURS</span><span>FR</span></div>
            <div class="login-form-wrap">
                <div class="mobile-brand"><x-brand /></div>
                <span class="form-kicker">HEUREUX DE VOUS REVOIR</span>
                <h2 id="login-title">Votre espace Titan.</h2>
                <p class="form-intro">Connectez-vous pour retrouver votre espace de travail.</p>

                @if (session('status'))
                    <div class="notice notice-success" role="status"><x-icon name="check" /><span>{{ session('status') }}</span></div>
                @endif

                <form method="POST" action="{{ route('login.store') }}" class="login-form" data-submit-form>
                    @csrf
                    <div class="field">
                        <label for="email">Adresse e-mail</label>
                        <div class="input-wrap @error('email') is-invalid @enderror">
                            <x-icon name="mail" />
                            <input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="vous@entreprise.com" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="255" required autofocus @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                        </div>
                        @error('email')<p class="field-error" id="email-error" role="alert">{{ $message }}</p>@enderror
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
                        <button type="button" class="text-button" data-open-help>Besoin d’aide ?</button>
                    </div>
                    <button class="primary-button" type="submit" data-submit-button><span data-button-label>Se connecter</span><x-icon name="arrow" /><span class="button-spinner" hidden></span></button>
                </form>

                <div class="access-note"><span class="note-icon"><x-icon name="shield" /></span><p><strong>Un accès réservé à votre équipe</strong>Votre compte est créé par votre administrateur.</p></div>
            </div>
            <footer class="login-footer"><span>© {{ date('Y') }} Titan Transfer</span><span>Chaque transfert rapproche.</span></footer>
        </section>
    </main>
    <dialog id="login-help" class="help-dialog" aria-labelledby="help-title">
        <span class="help-icon"><x-icon name="help" /></span>
        <h2 id="help-title">Un problème de connexion ?</h2>
        <p>Pour obtenir un accès ou réinitialiser votre mot de passe, contactez l’administrateur de votre agence.</p>
        <p>Vérifiez également votre adresse e-mail et la touche de verrouillage des majuscules.</p>
        <form method="dialog"><button class="primary-button">Compris <x-icon name="check" /></button></form>
    </dialog>
</x-layouts.base>
