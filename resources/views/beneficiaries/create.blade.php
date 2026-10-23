<x-layouts.workspace title="Nouveau bénéficiaire">
    <x-page-heading title="Nouveau bénéficiaire" />
    <form method="POST" action="{{ route('beneficiaries.store') }}" class="editor-form">
        @csrf
        @if($customer)
            <div class="form-context"><strong>Client associé : {{ $customer->first_name }} {{ $customer->last_name }}</strong><span>{{ $customer->customer_number }}</span></div>
            <input type="hidden" name="customer_id" value="{{ $customer->id }}">
        @else
            <p class="form-context">Vous pouvez associer un bénéficiaire à un client depuis la <a class="text-button" href="{{ route('customers.index') }}">liste des clients</a>.</p>
        @endif
        @error('customer_id')<p class="field-error" role="alert">{{ $message }}</p>@enderror

        <div class="form-grid">
            <x-field name="branch_id" label="Agence de rattachement" required>
                <option value="">Choisir une agence</option>
                @foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id', $customer?->branch_id ?? auth()->user()->branch_id) == $branch->id)>{{ $branch->name }}</option>@endforeach
            </x-field>
            <x-field name="first_name" label="Prénom" required maxlength="100" autocomplete="given-name" />
            <x-field name="last_name" label="Nom" maxlength="100" autocomplete="family-name" />
            <x-field name="phone" label="Téléphone" type="tel" required maxlength="32" placeholder="+243…" autocomplete="tel" />
            
            <x-field name="country" label="Pays" required maxlength="100" />
            <x-field name="city" label="Ville" maxlength="100" />
            <x-field name="address" label="Adresse" maxlength="500" autocomplete="street-address" />
            <x-field name="id_type" label="Type de pièce d’identité" maxlength="50" placeholder="Passeport, carte d’identité…" />
            <x-field name="id_number" label="Numéro de pièce d’identité" maxlength="100" />
        </div>
        <div class="form-actions"><a class="secondary-button" href="{{ route('beneficiaries.index') }}">Annuler</a><button class="primary-button compact-button">Enregistrer</button></div>
    </form>
</x-layouts.workspace>
