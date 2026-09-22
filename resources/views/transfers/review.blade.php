<x-layouts.workspace title="Vérifier l’opération">
    <x-page-heading title="Vérifier l’opération" description="Vérifiez les coordonnées et les montants avant l’encaissement." />
    <section class="editor-form transfer-review">
        <div class="transfer-route"><strong>{{ $source->name }}</strong><x-icon name="arrow" /><strong>{{ $destination->name }}</strong></div>
        <div class="form-grid party-summary">
            @foreach(['sender' => 'Client expéditeur', 'beneficiary' => 'Bénéficiaire'] as $party => $label)
                <div><span>{{ $label }} @if($data[$party.'_mode'] === 'new')<small class="pill">Nouveau</small>@endif</span><strong>{{ $snapshot[$party]['first_name'] }} {{ $snapshot[$party]['last_name'] }}</strong><p>{{ $snapshot[$party]['phone'] }} · {{ $snapshot[$party]['country'] }}</p></div>
            @endforeach
        </div>
        <x-transfer-summary :amount="$quote->amountReceived" :percentage="$quote->feePercentage" :fee="$quote->feeAmount" :net="$quote->netAmount" :rate="$quote->exchangeRate" :payout="$quote->payoutAmount" :source-currency="$source->default_currency" :destination-currency="$destination->default_currency" />
        @if($data['notes'] ?? null)<p class="form-context">{{ $data['notes'] }}</p>@endif
        <form method="POST" action="{{ route('transfers.store') }}" data-confirm-transfer>
            @csrf<input type="hidden" name="review_token" value="{{ $token }}">
            <label class="cash-confirmation"><input type="checkbox" name="cash_received" value="1" required> J’ai reçu {{ $quote->amountReceived }} {{ $source->default_currency }} du client.</label>
            <p class="field-hint">La confirmation enregistre l’encaissement et place le transfert en attente de validation. Tarifs à revérifier après 10 minutes.</p>
            <div class="form-actions"><button type="submit" class="secondary-button" formaction="{{ route('transfers.revise') }}" formnovalidate>Modifier</button><button type="submit" class="primary-button compact-button" data-confirm-button>Confirmer l’opération</button></div>
        </form>
    </section>
</x-layouts.workspace>
