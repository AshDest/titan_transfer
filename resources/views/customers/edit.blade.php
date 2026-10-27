<x-layouts.workspace title="Modifier le client">
    <x-page-heading title="Modifier le client" :description="$customer->customer_number.' · '.$customer->branch->name" />
    <form method="POST" action="{{ route('customers.update', $customer) }}" class="editor-form">
        @csrf
        @method('PUT')
        <div class="form-grid">
            <x-person-name :first-name="$customer->first_name" :last-name="$customer->last_name" />
            <x-field name="phone" label="Téléphone" :value="$customer->phone" type="tel" required maxlength="32" autocomplete="tel" />
            <x-field name="email" label="Adresse e-mail" :value="$customer->email" type="email" maxlength="255" autocomplete="email" />
            <x-field name="country" label="Pays" :value="$customer->country" required maxlength="100" />
            <x-field name="city" label="Ville" :value="$customer->city" maxlength="100" />
            <x-field name="address" label="Adresse" :value="$customer->address" maxlength="500" autocomplete="street-address" />
            <x-field name="id_type" label="Type de pièce d’identité" :value="$customer->id_type" maxlength="50" />
            <x-field name="id_number" label="Numéro de pièce d’identité" :value="$customer->id_number" maxlength="100" />
            <x-field name="status" label="Statut" required>
                <option value="ACTIVE" @selected(old('status', $customer->status->value) === 'ACTIVE')>Actif</option>
                <option value="INACTIVE" @selected(old('status', $customer->status->value) === 'INACTIVE')>Inactif</option>
            </x-field>
        </div>
        <div class="form-actions"><a class="secondary-button" href="{{ route('customers.index') }}">Annuler</a><button class="primary-button compact-button">Enregistrer les modifications</button></div>
    </form>
    @can('delete', $customer)
        <x-delete-record :action="route('customers.destroy', $customer)" label="le client" hint="Client sans bénéficiaires ni transferts uniquement. Sinon, désactivez-le." />
    @endcan
</x-layouts.workspace>
