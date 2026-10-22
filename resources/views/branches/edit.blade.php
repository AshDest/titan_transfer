<x-layouts.workspace title="Modifier l’agence">
    <x-page-heading title="Modifier l’agence" :description="$branch->name" />
    <form method="POST" action="{{ route('branches.update', $branch) }}" class="editor-form">
        @csrf
        @method('PUT')
        <div class="form-grid">
            <x-field name="name" label="Nom de l’agence" :value="$branch->name" required maxlength="255" />
            <x-field name="code" label="Code" :value="$branch->code" required maxlength="12" pattern="[A-Z0-9]{2,12}" />
            <x-field name="country" label="Pays" :value="$branch->country" required maxlength="100" />
            <x-field name="city" label="Ville" :value="$branch->city" required maxlength="100" />
            <x-field name="default_currency" label="Devise par défaut" :value="$branch->default_currency" required maxlength="3" pattern="[A-Z]{3}" hint="Modifiable uniquement avant utilisation financière." />
            <x-field name="timezone" label="Fuseau horaire" required>
                @foreach(DateTimeZone::listIdentifiers() as $zone)<option value="{{ $zone }}" @selected(old('timezone', $branch->timezone) === $zone)>{{ $zone }}</option>@endforeach
            </x-field>
            <x-field name="status" label="Statut" required hint="Inactive = opérations bloquées.">
                <option value="ACTIVE" @selected(old('status', $branch->status->value) === 'ACTIVE')>Active</option>
                <option value="INACTIVE" @selected(old('status', $branch->status->value) === 'INACTIVE')>Inactive</option>
            </x-field>
        </div>
        <div class="form-actions"><a class="secondary-button" href="{{ route('branches.index') }}">Annuler</a><button class="primary-button compact-button">Enregistrer les modifications</button></div>
    </form>
    @can('delete', $branch)
        <x-delete-record :action="route('branches.destroy', $branch)" label="l’agence" hint="Agence sans données liées uniquement. Sinon, désactivez-la." />
    @endcan
</x-layouts.workspace>
