<x-layouts.workspace title="Modifier l’utilisateur">
    <x-page-heading title="Modifier l’utilisateur" />
    <form method="POST" action="{{ route('users.update', $user) }}" class="editor-form">
        @csrf
        @method('PUT')
        <div class="form-grid">
            <x-field name="name" label="Nom complet" :value="$user->name" required maxlength="255" autocomplete="name" />
            <x-field name="email" label="Adresse e-mail" type="email" :value="$user->email" required maxlength="255" autocomplete="off" />
            <x-field name="phone" label="Téléphone de connexion" type="tel" :value="$user->phone" maxlength="32" autocomplete="tel" placeholder="+243970000001" />
            <x-field name="role" label="Rôle" required>
                <option value="">Sélectionner un rôle</option>
                @foreach($roles as $role)<option value="{{ $role->value }}" @selected(old('role', $user->roles->count() === 1 ? $user->roles->first()->name : null) === $role->value)>{{ $role->label() }}</option>@endforeach
            </x-field>
            <x-field name="branch_id" label="Agence" hint="Obligatoire sauf pour un administrateur.">
                <option value="">Sans agence</option>
                @foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) old('branch_id', $user->branch_id) === (string) $branch->id)>{{ $branch->name }} ({{ $branch->code }})</option>@endforeach
            </x-field>
            <x-field name="password" label="Nouveau mot de passe" type="password" minlength="12" maxlength="72" autocomplete="new-password" hint="Laissez vide pour conserver le mot de passe actuel." />
            <x-field name="password_confirmation" label="Confirmer le nouveau mot de passe" type="password" minlength="12" maxlength="72" autocomplete="new-password" />
        </div>
        <div class="form-actions"><a class="secondary-button" href="{{ route('users.index') }}">Annuler</a><button class="primary-button compact-button">Enregistrer les modifications</button></div>
    </form>
</x-layouts.workspace>
