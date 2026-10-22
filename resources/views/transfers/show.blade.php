<x-layouts.workspace title="Détail du transfert">
    <x-page-heading :title="$transfer->reference" :create="auth()->user()->can('transfers.create') ? 'transfers.create' : null" button="Nouvelle opération" />
    @if($errors->any())<div class="notice page-notice" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <section class="editor-form transfer-review">
        <div class="receipt-heading"><x-brand /><span class="pill">{{ $transfer->status->label() }}</span></div>
        <p class="field-hint">Reçu d’encaissement · {{ $transfer->reference }} · {{ $transfer->created_at->format('d/m/Y H:i') }}</p>
        <div class="transfer-route"><strong>{{ $transfer->sourceBranch->name }}</strong><x-icon name="arrow" /><strong>{{ $transfer->destinationBranch->name }}</strong></div>
        <div class="form-grid party-summary">
            @foreach(['sender' => 'Client expéditeur', 'beneficiary' => 'Bénéficiaire'] as $party => $label)
                <div><span>{{ $label }}</span><strong>{{ $transfer->parties_snapshot[$party]['first_name'] }} {{ $transfer->parties_snapshot[$party]['last_name'] }}</strong><p>{{ $transfer->parties_snapshot[$party]['phone'] }} · {{ $transfer->parties_snapshot[$party]['country'] }}</p></div>
            @endforeach
        </div>
        <x-transfer-summary :fee-type="$transfer->fee_type" :amount="$transfer->amount_received" :percentage="$transfer->fee_percentage" :fee="$transfer->fee_amount" :net="$transfer->net_amount" :rate="$transfer->exchange_rate" :payout="$transfer->payout_amount" :source-currency="$transfer->source_currency" :destination-currency="$transfer->destination_currency" />
        @if($transfer->notes)<p class="form-context">{{ $transfer->notes }}</p>@endif
        @if($transfer->status === \App\Enums\TransferStatus::Pending)<p class="form-context">Encaissement enregistré. Le transfert attend sa validation avant la mise à disposition des fonds.</p>@endif
        @if($transfer->approved_at)<p class="form-context">Approuvé par <strong>{{ $transfer->approvedBy->name }}</strong> le {{ $transfer->approved_at->timezone(auth()->user()->branch?->timezone ?? 'UTC')->format('d/m/Y H:i') }}.</p>@endif
        @if($transfer->rejected_at)<div class="form-context rejection-context"><strong>Rejeté par {{ $transfer->rejectedBy->name }}</strong><p>{{ $transfer->rejection_reason }}</p><p>Le dépôt reste en caisse jusqu’au remboursement effectif du client.</p></div>@endif
        <p class="field-hint receipt-print-help">Impression thermique : papier 80 mm, échelle 100 %, en-têtes et pieds de page désactivés.</p>
        <div class="form-actions receipt-actions"><a class="secondary-button" href="{{ route('transfers.index') }}">Tous les transferts</a><button type="button" class="primary-button compact-button" data-print-receipt>Imprimer le reçu · 80 mm</button></div>
    </section>
    @if($transfer->status === \App\Enums\TransferStatus::Pending)
        @canany(['approve', 'reject'], $transfer)
            <section class="review-decision" aria-labelledby="decision-title">
                <h2 id="decision-title">Validation du transfert</h2>
                <p>Vérifiez l’encaissement, le bénéficiaire et les montants avant de prendre une décision.</p>
                @can('approve', $transfer)
                    <form method="POST" action="{{ route('transfers.approve', $transfer) }}" data-review-transfer>
                        @csrf<label class="cash-confirmation"><input type="checkbox" name="confirmed" value="1" required> J’ai vérifié les informations et l’encaissement de ce transfert.</label>
                        <button class="primary-button compact-button">Approuver le transfert</button>
                    </form>
                @endcan
                @can('reject', $transfer)
                    <details class="reject-transfer" @error('rejection_reason') open @enderror><summary>Rejeter le transfert</summary>
                        <form method="POST" action="{{ route('transfers.reject', $transfer) }}" data-review-transfer>
                            @csrf<div class="field"><label for="rejection_reason">Motif du rejet</label><textarea class="form-control" name="rejection_reason" id="rejection_reason" rows="3" minlength="5" maxlength="1000" required>{{ is_string(old('rejection_reason')) ? old('rejection_reason') : '' }}</textarea></div>
                            <p class="field-hint">Le rejet bloque ce transfert. Il n’enregistre pas de remboursement du client.</p>
                            <button class="danger-button">Confirmer le rejet</button>
                        </form>
                    </details>
                @endcan
            </section>
        @endcanany
    @endif
    <section class="transfer-history"><h2>Historique</h2>
        @foreach($transfer->events as $event)<p><strong>{{ $event->created_at->format('d/m/Y H:i') }}</strong> · {{ match($event->action) { 'TRANSFER_CREATED' => 'Opération créée et dépôt en caisse enregistré', 'TRANSFER_APPROVED' => 'Transfert approuvé', 'TRANSFER_REJECTED' => 'Transfert rejeté', default => $event->action } }} · {{ $event->actor->name }}</p>@endforeach
    </section>
    <x-thermal-receipt :transfer="$transfer" :auto-print="$printReceipt" />
</x-layouts.workspace>
