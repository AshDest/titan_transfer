<x-layouts.workspace title="Sortie de caisse">
    <x-page-heading title="Sortie de caisse" description="Enregistrez les espèces remises à un bénéficiaire. Le montant sera déduit de la caisse choisie." />
    @if($accounts->isEmpty())
        <section class="editor-form"><p>Aucune caisse active disponible dans vos agences. Un encaissement doit alimenter la caisse avant toute sortie.</p><a class="secondary-button" href="{{ route('treasury.index') }}">Retour à la trésorerie</a></section>
    @else
        <form method="POST" action="{{ route('treasury.cash-outs.store') }}" class="editor-form">
            @csrf
            <input type="hidden" name="submission_key" value="{{ is_string(old('submission_key', $submissionKey)) ? old('submission_key', $submissionKey) : $submissionKey }}">
            @error('submission_key')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            <div class="form-grid">
                <x-field name="cash_account_id" label="Caisse" required>
                    <option value="">Sélectionner une caisse</option>
                    @foreach($accounts as $account)<option value="{{ $account->id }}" @selected(is_scalar(old('cash_account_id', $accounts->count() === 1 ? $accounts->first()->id : '')) && (string) old('cash_account_id', $accounts->count() === 1 ? $accounts->first()->id : '') === (string) $account->id)>{{ $account->branch->name }} · {{ $account->currency }} · solde : @money($account->balance) {{ $account->currency }}</option>@endforeach
                </x-field>
                <x-field name="amount" label="Montant de la sortie" required inputmode="decimal" placeholder="100.00" hint="Dans la devise de la caisse sélectionnée. Deux décimales maximum, séparées par un point." />
                <x-field name="occurred_on" label="Date de l’opération" type="date" required :value="now(auth()->user()->branch?->timezone ?? 'UTC')->toDateString()" hint="Date dans l’agence choisie. Les dates futures sont refusées." />
                <x-field name="recipient_name" label="Personne bénéficiaire" required maxlength="255" />
                <x-field name="description" label="Motif de la sortie" required maxlength="255" />
            </div>
            <label class="checkbox-label"><input type="checkbox" name="confirmed" value="1" required> Je confirme avoir remis ce montant au bénéficiaire.</label>
            @error('confirmed')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            <div class="form-actions"><a class="secondary-button" href="{{ route('treasury.index') }}">Annuler</a><button class="primary-button compact-button">Enregistrer la sortie</button></div>
        </form>
    @endif
</x-layouts.workspace>
