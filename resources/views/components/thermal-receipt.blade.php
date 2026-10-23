@props(['transfer', 'autoPrint' => false])
<article class="thermal-receipt" data-thermal-receipt @if($autoPrint) data-auto-print @endif aria-hidden="true">
    <header class="thermal-heading">
        <img class="thermal-logo" src="{{ asset('images/brand/titan-express-dark.png') }}" alt="Titan Express" width="8334" height="2313">
        <p>{{ $transfer->status === \App\Enums\TransferStatus::Paid ? 'REÇU DE PAIEMENT' : 'REÇU D’ENCAISSEMENT' }}</p>
        <strong>{{ $transfer->reference }}</strong>
        @php($receiptTimezone = $transfer->status === \App\Enums\TransferStatus::Paid ? $transfer->destinationBranch->timezone : $transfer->sourceBranch->timezone)
        <p>{{ ($transfer->paid_at ?? $transfer->created_at)->copy()->timezone($receiptTimezone)->format('d/m/Y H:i') }} · {{ $receiptTimezone }}</p>
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
        <div><dt>Total encaissé</dt><dd>@money($transfer->amount_received) {{ $transfer->source_currency }}</dd></div>
        <div><dt>@if($transfer->fee_type === \App\Enums\FeeType::Fixed)Frais fixes
            @else
                Frais (<span title="Pourcentage exact : {{ $transfer->fee_percentage }} %">@percentage($transfer->fee_percentage)</span> %)@endif</dt><dd>@money($transfer->fee_amount) {{ $transfer->source_currency }}</dd></div>
        <div><dt>Montant net</dt><dd>@money($transfer->net_amount) {{ $transfer->source_currency }}</dd></div>
        <div class="thermal-rate"><dt>Taux de change</dt><dd>1 {{ $transfer->source_currency }} = <span title="Taux exact : {{ $transfer->exchange_rate }}">@rate($transfer->exchange_rate)</span> {{ $transfer->destination_currency }}</dd></div>
        <div class="thermal-payout"><dt>{{ $transfer->status === \App\Enums\TransferStatus::Paid ? 'Montant remis' : 'À retirer' }}</dt><dd>@money($transfer->payout_amount) {{ $transfer->destination_currency }}</dd></div>
    </dl>
    <footer class="thermal-footer">
        <p><b>{{ $transfer->status->label() }}</b></p>
        @if($transfer->status === \App\Enums\TransferStatus::Pending)<p>Fonds disponibles après validation du transfert.</p>@endif
        @if($transfer->status === \App\Enums\TransferStatus::Rejected)<p>Transfert rejeté. Contactez l’agence de départ pour le remboursement.</p>@endif
        <p>Conservez ce reçu. Merci de votre confiance.</p>
    </footer>
</article>
