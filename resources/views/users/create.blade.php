<x-layouts.workspace title="Nouvel utilisateur">
    <x-page-heading title="Nouvel utilisateur" description="Créez un compte et définissez ses accès à l’application." />
    <form method="POST" action="{{ route('users.store') }}" class="editor-form">
        @csrf
        <div class="form-grid">
            <x-field name="name" label="Nom complet" required maxlength="255" autocomplete="name" />
            <x-field name="email" label="Adresse e-mail" type="email" required maxlength="255" autocomplete="off" />
            <x-field name="phone" label="Téléphone de connexion" type="tel" maxlength="32" autocomplete="tel" placeholder="+243970000001" hint="Facultatif. Numéro international avec indicatif. Le compte pourra se connecter par e-mail ou téléphone, avec le même mot de passe." />
            <x-field name="role" label="Rôle" required>
                <option value="">Sélectionner un rôle</option>
                @foreach($roles as $role)<option value="{{ $role->value }}" @selected(old('role') === $role->value)>{{ $role->label() }}</option>@endforeach
            </x-field>
            <x-field name="branch_id" label="Agence" hint="Obligatoire pour les caissiers et les responsables d’agence. Facultative pour un administrateur.">
                <option value="">Sélectionner une agence</option>
                @foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) old('branch_id') === (string) $branch->id)>{{ $branch->name }} ({{ $branch->code }})</option>@endforeach
            </x-field>
            <x-field name="password" label="Mot de passe" type="password" required minlength="12" maxlength="72" autocomplete="new-password" hint="Au moins 12 caractères, avec des lettres et des chiffres." />
            <x-field name="password_confirmation" label="Confirmer le mot de passe" type="password" required minlength="12" maxlength="72" autocomplete="new-password" />
        </div>
        <div class="form-actions"><a class="secondary-button" href="{{ route('users.index') }}">Annuler</a><button class="primary-button compact-button">Créer l’utilisateur</button></div>
    </form>
</x-layouts.workspace>
