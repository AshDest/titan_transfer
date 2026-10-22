<x-layouts.workspace title="Nouvelle règle de frais">
    <x-page-heading title="Nouvelle règle de frais" />
    <form method="POST" action="{{ route('fee-rules.store') }}" class="editor-form">
        @csrf
        <div class="form-grid">
            <x-field name="name" label="Nom de la règle" required maxlength="255" />
            <x-field name="source_currency" label="Devise des montants" required maxlength="3" pattern="[A-Z]{3}" placeholder="USD" />
            <x-field name="source_branch_id" label="Agence d’origine"><option value="">Toutes les agences</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('source_branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</x-field>
            <x-field name="destination_branch_id" label="Agence de destination"><option value="">Toutes les agences</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('destination_branch_id') == $branch->id)>{{ $branch->name }}</option>@endforeach</x-field>
            <x-field name="percentage" label="Pourcentage de cette tranche" required inputmode="decimal" placeholder="Ex. 3" hint="0 à 100 %." />
            <x-field name="active" label="Configuration"><option value="1" @selected(old('active', '1') == '1')>Activée</option><option value="0" @selected(old('active', '1') == '0')>Désactivée</option></x-field>
            <x-field name="minimum_amount" label="Début de la tranche (inclus)" inputmode="decimal" placeholder="0.00" hint="Vide = 0." />
            <x-field name="maximum_amount" label="Fin de la tranche (incluse)" inputmode="decimal" placeholder="Sans plafond" hint="Vide = aucun plafond." />
            <x-field name="effective_from" label="Date de début (UTC)" type="datetime-local" :value="now('UTC')->format('Y-m-d\TH:i')" />
            <x-field name="effective_until" label="Date de fin (UTC)" type="datetime-local" />
        </div>
        <div class="form-actions"><a class="secondary-button" href="{{ route('fee-rules.index') }}">Annuler</a><button class="primary-button compact-button">Enregistrer la règle</button></div>
    </form>
</x-layouts.workspace>
