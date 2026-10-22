@props(['amount', 'percentage', 'fee', 'net', 'rate', 'payout', 'sourceCurrency', 'destinationCurrency', 'feeType' => \App\Enums\FeeType::Percentage])
<div class="transfer-totals">
    <dl>
        <div><dt>Montant encaissé</dt><dd>@money($amount) {{ $sourceCurrency }}</dd></div>
        <div><dt>@if($feeType === \App\Enums\FeeType::Fixed)Frais fixes
            @else
                Frais (<span title="Pourcentage exact : {{ $percentage }} %">@percentage($percentage)</span> %)@endif</dt><dd>@money($fee) {{ $sourceCurrency }}</dd></div>
        <div><dt>Montant net à convertir</dt><dd>@money($net) {{ $sourceCurrency }}</dd></div>
        <div><dt>Taux de change</dt><dd>1 {{ $sourceCurrency }} = <span title="Taux exact : {{ $rate }}">@rate($rate)</span> {{ $destinationCurrency }}</dd></div>
        <div class="payout-total"><dt>À retirer par le bénéficiaire</dt><dd>@money($payout) {{ $destinationCurrency }}</dd></div>
    </dl>
</div>
