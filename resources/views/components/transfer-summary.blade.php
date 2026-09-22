@props(['amount', 'percentage', 'fee', 'net', 'rate', 'payout', 'sourceCurrency', 'destinationCurrency'])
<div class="transfer-totals">
    <dl>
        <div><dt>Montant encaissé</dt><dd>{{ $amount }} {{ $sourceCurrency }}</dd></div>
        <div><dt>Frais ({{ $percentage }} %)</dt><dd>{{ $fee }} {{ $sourceCurrency }}</dd></div>
        <div><dt>Montant net à convertir</dt><dd>{{ $net }} {{ $sourceCurrency }}</dd></div>
        <div><dt>Taux de change</dt><dd>1 {{ $sourceCurrency }} = {{ $rate }} {{ $destinationCurrency }}</dd></div>
        <div class="payout-total"><dt>À retirer par le bénéficiaire</dt><dd>{{ $payout }} {{ $destinationCurrency }}</dd></div>
    </dl>
</div>
