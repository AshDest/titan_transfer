<x-layouts.workspace title="Nouveau client">
    <x-page-heading title="Nouveau client" />
    <form method="POST" action="{{ route('customers.store') }}" class="editor-form">
        @csrf

        <div class="form-grid">
            <x-field name="branch_id" label="Agence de rattachement" required>
                <option value="">Choisir une agence</option>
                @foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id', auth()->user()->branch_id) == $branch->id)>{{ $branch->name }}</option>@endforeach
            </x-field>
            <x-field name="first_name" label="Prénom" required maxlength="100" autocomplete="given-name" />
            <x-field name="last_name" label="Nom" required maxlength="100" autocomplete="family-name" />
            <x-field name="phone" label="Téléphone" type="tel" required maxlength="32" placeholder="+243…" autocomplete="tel" />
            <x-field name="email" label="Adresse e-mail" type="email" maxlength="255" autocomplete="email" />
            <x-field name="country" label="Pays" required maxlength="100" />
            <x-field name="city" label="Ville" maxlength="100" />
            <x-field name="address" label="Adresse" maxlength="500" autocomplete="street-address" />
            <x-field name="id_type" label="Type de pièce d’identité" maxlength="50" placeholder="Passeport, carte d’identité…" />
            <x-field name="id_number" label="Numéro de pièce d’identité" maxlength="100" />
        </div>
        <div class="form-actions"><a class="secondary-button" href="{{ route('customers.index') }}">Annuler</a><button class="primary-button compact-button">Enregistrer</button></div>
    </form>
</x-layouts.workspace>
