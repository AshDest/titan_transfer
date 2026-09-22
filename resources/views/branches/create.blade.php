<x-layouts.workspace title="Nouvelle agence">
    <x-page-heading title="Nouvelle agence" />
    <form method="POST" action="{{ route('branches.store') }}" class="editor-form">
        @csrf
        <div class="form-grid">
            <x-field name="name" label="Nom de l’agence" required maxlength="255" />
            <x-field name="code" label="Code" required maxlength="12" pattern="[A-Z0-9]{2,12}" hint="2 à 12 lettres majuscules ou chiffres." />
            <x-field name="country" label="Pays" required maxlength="100" />
            <x-field name="city" label="Ville" required maxlength="100" />
            <x-field name="default_currency" label="Devise par défaut" required maxlength="3" pattern="[A-Z]{3}" placeholder="USD" hint="Code de devise à trois lettres." />
            <x-field name="timezone" label="Fuseau horaire" required value="UTC">
                @foreach(DateTimeZone::listIdentifiers() as $zone)<option value="{{ $zone }}" @selected(old('timezone', 'UTC') === $zone)>{{ $zone }}</option>@endforeach
            </x-field>
        </div>
        <div class="form-actions"><a class="secondary-button" href="{{ route('branches.index') }}">Annuler</a><button class="primary-button compact-button">Créer l’agence</button></div>
    </form>
</x-layouts.workspace>
