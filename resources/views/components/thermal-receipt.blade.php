@props(['transfer', 'autoPrint' => false])
<article class="thermal-receipt" data-thermal-receipt @if($autoPrint) data-auto-print @endif aria-hidden="true">
    <header class="thermal-heading">
        <img class="thermal-logo" src="{{ asset('images/brand/titan-express-dark.png') }}" alt="Titan Express" width="8334" height="2313">
        <p>REÇU D’ENCAISSEMENT</p>
        <strong>{{ $transfer->reference }}</strong>
        <p>{{ $transfer->created_at->timezone($transfer->sourceBranch->timezone)->format('d/m/Y H:i') }} · {{ $transfer->sourceBranch->timezone }}</p>
    </header>
    <section class="thermal-section">
        <p><b>Départ :</b> {{ $transfer->sourceBranch->name }}</p>
        <p><b>Retrait :</b> {{ $transfer->destinationBranch->name }}</p>
    </section>
    <section class="thermal-section">
        @foreach(['sender' => 'Expéditeur', 'beneficiary' => 'Bénéficiaire'] as $party => $label)
            <div class="thermal-person"><p><b>{{ $label }} :</b> {{ $transfer->parties_snapshot[$party]['first_name'] }} {{ $transfer->parties_snapshot[$party]['last_name'] }}</p><p>Tél. {{ $transfer->parties_snapshot[$party]['phone'] }}</p></div>
        @endforeach
    </section>
    <dl class="thermal-amounts thermal-section">
        <div><dt>Total encaissé</dt><dd>{{ $transfer->amount_received }} {{ $transfer->source_currency }}</dd></div>
        <div><dt>Frais ({{ $transfer->fee_percentage }} %)</dt><dd>{{ $transfer->fee_amount }} {{ $transfer->source_currency }}</dd></div>
        <div><dt>Montant net</dt><dd>{{ $transfer->net_amount }} {{ $transfer->source_currency }}</dd></div>
        <div class="thermal-rate"><dt>Taux de change</dt><dd>1 {{ $transfer->source_currency }} = {{ $transfer->exchange_rate }} {{ $transfer->destination_currency }}</dd></div>
        <div class="thermal-payout"><dt>À retirer</dt><dd>{{ $transfer->payout_amount }} {{ $transfer->destination_currency }}</dd></div>
    </dl>
    <footer class="thermal-footer">
        <p><b>{{ $transfer->status->label() }}</b></p>
        @if($transfer->status === \App\Enums\TransferStatus::Pending)<p>Fonds disponibles après validation du transfert.</p>@endif
        <p>Conservez ce reçu. Merci de votre confiance.</p>
    </footer>
</article>
