@props(['transfer', 'balance', 'canPay'])
<dialog class="payment-dialog" id="payment-{{ $transfer->id }}" aria-labelledby="payment-title-{{ $transfer->id }}">
    <form method="POST" action="{{ route('transfers.pay', $transfer) }}" data-payment-form>
        @csrf
        <h2 id="payment-title-{{ $transfer->id }}">Confirmer le paiement</h2>
        <p class="field-hint">{{ $transfer->reference }} · {{ $transfer->destinationBranch->name }}</p>
        <p><strong>{{ $transfer->parties_snapshot['beneficiary']['first_name'] }} {{ $transfer->parties_snapshot['beneficiary']['last_name'] }}</strong></p>
        <p class="payment-dialog-amount">@money($transfer->payout_amount) {{ $transfer->destination_currency }}</p>
        <p>Solde en caisse : @money($balance) {{ $transfer->destination_currency }}</p>
        <label class="cash-confirmation"><input type="checkbox" name="confirmed" value="1" required @disabled(! $canPay)> J’ai vérifié l’identité du bénéficiaire et lui ai remis ce montant.</label>
        <div class="form-actions">
            <button type="button" class="secondary-button" data-close-payment-dialog>Annuler</button>
            <button type="submit" class="primary-button compact-button" @disabled(! $canPay)>Confirmer le paiement</button>
        </div>
    </form>
</dialog>
