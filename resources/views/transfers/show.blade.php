<x-layouts.workspace title="Détail du transfert">
    <x-page-heading :title="$transfer->reference" :create="auth()->user()->can('transfers.create') ? 'transfers.create' : null" button="Nouvelle opération" />
    <section class="editor-form transfer-review">
        <div class="receipt-heading"><x-brand /><span class="pill">{{ $transfer->status->label() }}</span></div>
        <p class="field-hint">Reçu d’encaissement · {{ $transfer->reference }} · {{ $transfer->created_at->format('d/m/Y H:i') }}</p>
        <div class="transfer-route"><strong>{{ $transfer->sourceBranch->name }}</strong><x-icon name="arrow" /><strong>{{ $transfer->destinationBranch->name }}</strong></div>
        <div class="form-grid party-summary">
            @foreach(['sender' => 'Client expéditeur', 'beneficiary' => 'Bénéficiaire'] as $party => $label)
                <div><span>{{ $label }}</span><strong>{{ $transfer->parties_snapshot[$party]['first_name'] }} {{ $transfer->parties_snapshot[$party]['last_name'] }}</strong><p>{{ $transfer->parties_snapshot[$party]['phone'] }} · {{ $transfer->parties_snapshot[$party]['country'] }}</p></div>
            @endforeach
        </div>
        <x-transfer-summary :amount="$transfer->amount_received" :percentage="$transfer->fee_percentage" :fee="$transfer->fee_amount" :net="$transfer->net_amount" :rate="$transfer->exchange_rate" :payout="$transfer->payout_amount" :source-currency="$transfer->source_currency" :destination-currency="$transfer->destination_currency" />
        @if($transfer->notes)<p class="form-context">{{ $transfer->notes }}</p>@endif
        @if($transfer->status === \App\Enums\TransferStatus::Pending)<p class="form-context">Encaissement enregistré. Le transfert attend sa validation avant la mise à disposition des fonds.</p>@endif
        <p class="field-hint receipt-print-help">Impression thermique : papier 80 mm, échelle 100 %, en-têtes et pieds de page désactivés.</p>
        <div class="form-actions receipt-actions"><a class="secondary-button" href="{{ route('transfers.index') }}">Tous les transferts</a><button type="button" class="primary-button compact-button" data-print-receipt>Imprimer le reçu · 80 mm</button></div>
    </section>
    <section class="transfer-history"><h2>Historique</h2>
        @foreach($transfer->events as $event)<p><strong>{{ $event->created_at->format('d/m/Y H:i') }}</strong> · {{ $event->action === 'TRANSFER_CREATED' ? 'Opération créée et dépôt en caisse enregistré' : $event->action }} · {{ $event->actor->name }}</p>@endforeach
    </section>
    <x-thermal-receipt :transfer="$transfer" :auto-print="$printReceipt" />
</x-layouts.workspace>
