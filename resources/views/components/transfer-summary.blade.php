@props(['amount', 'percentage', 'fee', 'net', 'rate', 'payout', 'sourceCurrency', 'destinationCurrency'])
<div class="transfer-totals">
    <dl>
        <div><dt>Montant encaissé</dt><dd>@money($amount) {{ $sourceCurrency }}</dd></div>
        <div><dt>Frais (<span title="Pourcentage exact : {{ $percentage }} %">@percentage($percentage)</span> %)</dt><dd>@money($fee) {{ $sourceCurrency }}</dd></div>
        <div><dt>Montant net à convertir</dt><dd>@money($net) {{ $sourceCurrency }}</dd></div>
        <div><dt>Taux de change</dt><dd>1 {{ $sourceCurrency }} = <span title="Taux exact : {{ $rate }}">@rate($rate)</span> {{ $destinationCurrency }}</dd></div>
        <div class="payout-total"><dt>À retirer par le bénéficiaire</dt><dd>@money($payout) {{ $destinationCurrency }}</dd></div>
    </dl>
</div>
