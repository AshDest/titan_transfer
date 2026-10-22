<x-layouts.workspace title="Téléphone de connexion">
    <x-page-heading title="Téléphone de connexion" :description="$user->name.' · '.$user->email" />
    <form method="POST" action="{{ route('users.phone.update', $user) }}" class="editor-form">
        @csrf
        @method('PATCH')
        <x-field name="phone" label="Téléphone" type="tel" :value="$user->phone" maxlength="32" autocomplete="tel" placeholder="+243970000001" hint="Format international. Vide = connexion par e-mail uniquement." />
        <div class="form-actions"><a class="secondary-button" href="{{ route('users.index') }}">Annuler</a><button class="primary-button compact-button">Enregistrer</button></div>
    </form>
</x-layouts.workspace>
