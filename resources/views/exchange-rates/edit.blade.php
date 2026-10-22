<x-layouts.workspace title="Modifier le taux de change">
    <x-page-heading title="Modifier le taux de change" :description="$rate->from_currency.' → '.$rate->to_currency.' · Une nouvelle version sera créée pour conserver l’historique.'" />
    <form method="POST" action="{{ route('exchange-rates.update', $rate) }}" class="editor-form">
        @csrf
        @method('PUT')
        @error('activation')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        <div class="form-grid">
            <x-field name="rate" label="Taux pour une unité de la devise d’origine" :value="$rate->rate" required inputmode="decimal" hint="Jusqu’à 6 décimales, séparées par un point." />
            <x-field name="active" label="Configuration" hint="L’activation désactive les autres taux de cette paire de devises. Le taux doit être valable maintenant.">
                <option value="1" @selected(old('active', $rate->active ? '1' : '0') == '1')>Activée</option>
                <option value="0" @selected(old('active', $rate->active ? '1' : '0') == '0')>Désactivée</option>
            </x-field>
            <x-field name="effective_from" label="Date de début (UTC)" type="datetime-local" required step="1" :value="$rate->effective_from->format('Y-m-d\TH:i:s')" />
            <x-field name="effective_until" label="Date de fin (UTC)" type="datetime-local" step="1" :value="$rate->effective_until?->format('Y-m-d\TH:i:s')" hint="Laissez vide pour une durée indéterminée." />
        </div>
        <div class="form-actions"><a class="secondary-button" href="{{ route('exchange-rates.index') }}">Annuler</a><button class="primary-button compact-button">Enregistrer la nouvelle version</button></div>
    </form>
    @can('delete', $rate)
        <x-delete-record :action="route('exchange-rates.destroy', $rate)" label="le taux" hint="Seul un taux désactivé et jamais utilisé dans un transfert peut être supprimé." />
    @endcan
</x-layouts.workspace>
