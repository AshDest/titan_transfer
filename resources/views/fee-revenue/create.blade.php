<x-layouts.workspace title="Retirer des frais">
    <x-page-heading title="Retirer des frais" />
    <form method="POST" action="{{ route('fee-revenue.withdrawals.store') }}" class="editor-form treasury-withdrawal">
        @csrf
        <input type="hidden" name="cash_account_id" value="{{ $account->id }}">
        <input type="hidden" name="date_from" value="{{ $filters['date_from'] }}">
        <input type="hidden" name="date_to" value="{{ $filters['date_to'] }}">
        <input type="hidden" name="submission_key" value="{{ is_string(old('submission_key', $submissionKey)) ? old('submission_key', $submissionKey) : $submissionKey }}">
        <div class="form-context"><strong>{{ $account->branch->name }} · {{ $account->currency }}</strong><span>{{ \Carbon\CarbonImmutable::parse($filters['date_from'])->format('d/m/Y') }} — {{ \Carbon\CarbonImmutable::parse($filters['date_to'])->format('d/m/Y') }}</span></div>
        @foreach(['submission_key', 'cash_account_id', 'date_from', 'date_to'] as $field)@error($field)<p class="field-error" role="alert">{{ $message }}</p>@enderror @endforeach
        <div class="fee-withdrawal-summary">
            <div><span>Frais restants</span><strong>@money($summary['available']) {{ $account->currency }}</strong></div>
            <div><span>Solde en caisse</span><strong>@money($account->balance) {{ $account->currency }}</strong></div>
        </div>
        <div class="form-grid">
            <x-field name="amount" label="Montant à retirer" required inputmode="decimal" placeholder="0.00" />
            <x-field name="occurred_on" label="Date du retrait" type="date" required :value="now($account->branch->timezone)->toDateString()" />
            <x-field name="recipient_name" label="Personne bénéficiaire" required maxlength="255" />
        </div>
        <label class="checkbox-label treasury-confirmation"><input type="checkbox" name="confirmed" value="1" required> Je confirme avoir remis ce montant au bénéficiaire.</label>
        @error('confirmed')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        <div class="form-actions"><a class="secondary-button" href="{{ route('fee-revenue.index', $filters) }}">Annuler</a><button class="primary-button compact-button">Enregistrer le retrait</button></div>
    </form>
</x-layouts.workspace>
