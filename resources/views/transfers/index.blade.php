<x-layouts.workspace :title="$awaitingPayout ? 'Transferts à payer' : 'Transferts'">
    <x-page-heading :title="$awaitingPayout ? 'Transferts à payer' : 'Transferts'" :create="auth()->user()->can('transfers.create') ? 'transfers.create' : null" button="Nouvelle opération" />
    @if($errors->any())<div class="notice page-notice" role="alert"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
    <p class="field-hint">Dates de création des transferts · {{ $timezone }} · Journées de début et de fin incluses.</p>
    <form method="GET" action="{{ route('transfers.index') }}" class="transfer-filters">
        @if($awaitingPayout)<input type="hidden" name="awaiting_payout" value="1">@endif
        <x-field name="q" label="Référence" type="search" placeholder="Rechercher une référence…" maxlength="100" :value="request('q')" />
        <x-field name="status" label="Statut"><option value="">Tous les statuts</option>@foreach(\App\Enums\TransferStatus::cases() as $status)@if(! $awaitingPayout || in_array($status, [\App\Enums\TransferStatus::Approved, \App\Enums\TransferStatus::ReadyForPayout]))<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>@endif @endforeach</x-field>
        <x-field name="date_from" label="Date de début" type="date" :value="request('date_from')" />
        <x-field name="date_to" label="Date de fin" type="date" :value="request('date_to')" />
        <button class="secondary-button">Filtrer</button>
        @can('reviewAny', \App\Models\Transfer::class)<a class="text-button" href="{{ route('transfers.index', [...request()->only(['q', 'date_from', 'date_to']), 'status' => 'PENDING']) }}">À valider</a>@endcan
        <a class="text-button" href="{{ route('transfers.index', [...request()->only(['q', 'date_from', 'date_to']), 'status' => 'PAID']) }}">Bénéficiaires servis</a>
        @if(request()->filled('q') || request()->filled('status') || request()->filled('date_from') || request()->filled('date_to'))<a class="text-button" href="{{ route('transfers.index', $awaitingPayout ? ['awaiting_payout' => 1] : []) }}">Réinitialiser</a>@endif
    </form>
    <section class="branch-section transfer-totals" aria-labelledby="transfer-totals-title">
        <div class="section-heading"><div><h2 id="transfer-totals-title">Totaux des transferts filtrés</h2><p>{{ $records->total() }} transfert(s) · Toutes les pages · Montants par devise, selon les filtres sélectionnés.</p></div></div>
        <div class="table-scroll"><table>
            <thead><tr><th>Devise</th><th>Total encaissé</th><th>Total des frais</th><th>Total net à convertir</th><th>Total à retirer</th></tr></thead>
            <tbody>
                @forelse($totals as $currency => $total)
                    <tr><td><span class="currency-chip">{{ $currency }}</span></td><td>@money($total['received'])</td><td>@money($total['fees'])</td><td>@money($total['net'])</td><td><strong>@money($total['payout'])</strong></td></tr>
                @empty
                    <tr><td colspan="5">Aucun transfert pour ces filtres. Total : 0,00.</td></tr>
                @endforelse
            </tbody>
        </table></div>
    </section>
    <x-listing :searchable="false" :records="$records" :headings="['Référence', 'Expéditeur / Bénéficiaire', 'Trajet', 'Encaissé', 'Frais', 'À retirer', 'Statut', 'Actions']" placeholder="Rechercher une référence…">
        @foreach($records as $transfer)<tr>
            <td><strong>{{ $transfer->reference }}</strong><small>{{ $transfer->created_at->timezone($timezone)->format('d/m/Y H:i') }}</small></td>
            <td>{{ $transfer->parties_snapshot['sender']['first_name'] }} {{ $transfer->parties_snapshot['sender']['last_name'] }}<small>Pour : {{ $transfer->parties_snapshot['beneficiary']['first_name'] }} {{ $transfer->parties_snapshot['beneficiary']['last_name'] }}</small></td>
            <td>{{ $transfer->sourceBranch->code }} → {{ $transfer->destinationBranch->code }}</td>
            <td>@money($transfer->amount_received) {{ $transfer->source_currency }}</td>
            <td>@money($transfer->fee_amount) {{ $transfer->source_currency }}<small>@if($transfer->fee_type === \App\Enums\FeeType::Fixed)Montant fixe
                @else
                    @percentage($transfer->fee_percentage) % @endif</small></td>
            <td>@money($transfer->payout_amount) {{ $transfer->destination_currency }}</td>
            <td>
                <span class="pill">{{ $transfer->status === \App\Enums\TransferStatus::Paid ? 'Bénéficiaire servi' : $transfer->status->label() }}</span>
                @if($transfer->status === \App\Enums\TransferStatus::Paid)
                    @if($transfer->paid_at)<small>{{ $transfer->paid_at->copy()->timezone($timezone)->format('d/m/Y H:i') }} · {{ $timezone }}</small>@endif
                    @if($transfer->paidBy)<small>Par {{ $transfer->paidBy->name }}</small>@endif
                @endif
            </td>
            <td><div class="row-actions"><x-action-link :href="route('transfers.show', $transfer)" icon="eye" label="Voir le transfert" />
                @if(in_array($transfer->status, [\App\Enums\TransferStatus::Approved, \App\Enums\TransferStatus::ReadyForPayout]))
                    @can('pay', $transfer)
                        @php($payment = $paymentAvailability[$transfer->id])
                        <button type="button" class="action-icon" data-payment-dialog="payment-{{ $transfer->id }}" aria-haspopup="dialog" aria-controls="payment-{{ $transfer->id }}" title="{{ $payment['can_pay'] ? 'Confirmer la remise des fonds' : 'Caisse indisponible ou solde insuffisant' }}" aria-label="Confirmer la remise des fonds" @disabled(! $payment['can_pay'])><x-icon name="check" /></button>
                        <x-payment-confirmation :transfer="$transfer" :balance="$payment['balance']" :can-pay="$payment['can_pay']" />
                    @endcan
                @endif
            </div></td>
        </tr>@endforeach
    </x-listing>
</x-layouts.workspace>
