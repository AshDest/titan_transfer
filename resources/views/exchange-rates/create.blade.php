<x-layouts.workspace title="Nouveau taux de change">
    <x-page-heading title="Nouveau taux de change" />
    <form method="POST" action="{{ route('exchange-rates.store') }}" class="editor-form">
        @csrf
        @error('activation')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        <div class="form-grid">
            <x-field name="from_currency" label="Devise d’origine" required maxlength="3" pattern="[A-Z]{3}" placeholder="USD" />
            <x-field name="to_currency" label="Devise de destination" required maxlength="3" pattern="[A-Z]{3}" placeholder="AED" />
            <x-field name="rate" label="Taux pour une unité de la devise d’origine" required inputmode="decimal" placeholder="3.670000" hint="6 décimales maximum." />
            <x-field name="active" label="Configuration" hint="Un seul taux actif par paire de devises.">
                <option value="1" @selected(old('active', '1') == '1')>Activée</option><option value="0" @selected(old('active', '1') == '0')>Désactivée</option>
            </x-field>
            <x-field name="effective_from" label="Date de début (UTC)" type="datetime-local" required :value="now('UTC')->format('Y-m-d\TH:i')" />
            <x-field name="effective_until" label="Date de fin (UTC)" type="datetime-local" hint="Vide = sans échéance." />
        </div>
        <div class="form-actions"><a class="secondary-button" href="{{ route('exchange-rates.index') }}">Annuler</a><button class="primary-button compact-button">Enregistrer le taux</button></div>
    </form>
</x-layouts.workspace>
