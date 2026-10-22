<x-layouts.workspace title="Sortie de caisse">
    <x-page-heading title="Sortie de caisse" />
    @if($accounts->isEmpty())
        <section class="editor-form treasury-withdrawal"><p>Aucune caisse active disponible.</p><a class="secondary-button" href="{{ route('treasury.index') }}">Retour à la trésorerie</a></section>
    @else
        <form method="POST" action="{{ route('treasury.cash-outs.store') }}" class="editor-form treasury-withdrawal">
            @csrf
            <input type="hidden" name="submission_key" value="{{ is_string(old('submission_key', $submissionKey)) ? old('submission_key', $submissionKey) : $submissionKey }}">
            @error('submission_key')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            <fieldset class="treasury-form-section">
                <legend><x-icon name="cash" /> Caisse et montant</legend>
                <div class="form-grid treasury-withdrawal-grid">
                    <x-field name="cash_account_id" label="Caisse" required>
                        <option value="">Sélectionner une caisse</option>
                        @foreach($accounts as $account)<option value="{{ $account->id }}" @selected(is_scalar(old('cash_account_id', $accounts->count() === 1 ? $accounts->first()->id : '')) && (string) old('cash_account_id', $accounts->count() === 1 ? $accounts->first()->id : '') === (string) $account->id)>{{ $account->branch->name }} · {{ $account->currency }} · solde : @money($account->balance) {{ $account->currency }}</option>@endforeach
                    </x-field>
                    <x-field name="amount" label="Montant de la sortie" required inputmode="decimal" placeholder="100.00" hint="Devise de la caisse, 2 décimales maximum." />
                    <x-field name="occurred_on" label="Date de l’opération" type="date" required :value="now(auth()->user()->branch?->timezone ?? 'UTC')->toDateString()" hint="Date locale de l’agence." />
                </div>
            </fieldset>
            <fieldset class="treasury-form-section">
                <legend><x-icon name="users" /> Bénéficiaire</legend>
                <div class="form-grid">
                    <x-field name="recipient_name" label="Personne bénéficiaire" required maxlength="255" />
                    <x-field name="description" label="Motif de la sortie" required maxlength="255" />
                </div>
            </fieldset>
            <label class="checkbox-label treasury-confirmation"><input type="checkbox" name="confirmed" value="1" required> Je confirme avoir remis ce montant au bénéficiaire.</label>
            @error('confirmed')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            <div class="form-actions"><a class="secondary-button" href="{{ route('treasury.index') }}">Annuler</a><button class="primary-button compact-button">Enregistrer la sortie</button></div>
        </form>
    @endif
</x-layouts.workspace>
