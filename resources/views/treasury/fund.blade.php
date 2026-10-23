<x-layouts.workspace title="Approvisionner une caisse">
    <x-page-heading title="Approvisionner une caisse" />
    @if($branches->isEmpty())
        <section class="editor-form treasury-withdrawal"><p>Aucune agence active disponible.</p><a class="secondary-button" href="{{ route('treasury.index') }}">Retour à la trésorerie</a></section>
    @else
        <form method="POST" action="{{ route('treasury.cash-ins.store') }}" class="editor-form treasury-withdrawal" data-funding-form>
            @csrf
            <input type="hidden" name="submission_key" value="{{ is_string(old('submission_key', $submissionKey)) ? old('submission_key', $submissionKey) : $submissionKey }}">
            @error('submission_key')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            <fieldset class="treasury-form-section">
                <legend><x-icon name="cash" /> Caisse et montant</legend>
                <div class="form-grid treasury-withdrawal-grid">
                    <x-field name="branch_id" label="Agence à approvisionner" required>
                        <option value="">Sélectionner une agence</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" data-currency="{{ $branch->default_currency }}" @selected((string) old('branch_id', $branches->count() === 1 ? $branches->first()->id : '') === (string) $branch->id)>{{ $branch->name }} · {{ $branch->default_currency }} · solde : @money($branch->cashAccounts->firstWhere('currency', $branch->default_currency)?->balance ?? '0.00')</option>
                        @endforeach
                    </x-field>
                    <x-field name="currency" label="Devise" required>
                        <option value="">Sélectionner la devise</option>
                        @foreach($branches->pluck('default_currency')->unique() as $currency)<option value="{{ $currency }}" @selected(old('currency', $branches->count() === 1 ? $branches->first()->default_currency : '') === $currency)>{{ $currency }}</option>@endforeach
                    </x-field>
                    <x-field name="amount" label="Montant reçu" required inputmode="decimal" placeholder="1000.00" />
                    <x-field name="occurred_on" label="Date de réception" type="date" required :value="now(auth()->user()->branch?->timezone ?? 'UTC')->toDateString()" />
                </div>
            </fieldset>
            <fieldset class="treasury-form-section">
                <legend><x-icon name="users" /> Origine des fonds</legend>
                <div class="form-grid">
                    <x-field name="provider_name" label="Personne qui apporte l’argent" required maxlength="255" />
                    <x-field name="description" label="Motif / Référence" required maxlength="255" />
                </div>
            </fieldset>
            <label class="checkbox-label treasury-confirmation"><input type="checkbox" name="confirmed" value="1" required> Je confirme avoir reçu ces espèces dans la caisse de l’agence.</label>
            @error('confirmed')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            <div class="form-actions"><a class="secondary-button" href="{{ route('treasury.index') }}">Annuler</a><button class="primary-button compact-button">Enregistrer l’approvisionnement</button></div>
        </form>
    @endif
</x-layouts.workspace>
