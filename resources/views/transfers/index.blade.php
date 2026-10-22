<x-layouts.workspace title="Transferts">
    <x-page-heading title="Transferts" description="Retrouvez les opérations de votre agence." :create="auth()->user()->can('transfers.create') ? 'transfers.create' : null" button="Nouvelle opération" />
    @if($errors->any())<div class="notice page-notice" role="alert"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
    <p class="field-hint">Dates de création des transferts · {{ $timezone }} · Journées de début et de fin incluses.</p>
    <form method="GET" action="{{ route('transfers.index') }}" class="transfer-filters">
        <x-field name="q" label="Référence" type="search" placeholder="Rechercher une référence…" maxlength="100" :value="request('q')" />
        <x-field name="status" label="Statut"><option value="">Tous les statuts</option>@foreach(\App\Enums\TransferStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>@endforeach</x-field>
        <x-field name="date_from" label="Date de début" type="date" :value="request('date_from')" />
        <x-field name="date_to" label="Date de fin" type="date" :value="request('date_to')" />
        <button class="secondary-button">Filtrer</button>
        @can('reviewAny', \App\Models\Transfer::class)<a class="text-button" href="{{ route('transfers.index', [...request()->only(['q', 'date_from', 'date_to']), 'status' => 'PENDING']) }}">À valider</a>@endcan
        @if(request()->filled('q') || request()->filled('status') || request()->filled('date_from') || request()->filled('date_to'))<a class="text-button" href="{{ route('transfers.index') }}">Réinitialiser</a>@endif
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
    <x-listing :searchable="false" :records="$records" :headings="['Référence', 'Client', 'Trajet', 'Encaissé', 'Frais', 'À retirer', 'Statut', '']" placeholder="Rechercher une référence…">
        @foreach($records as $transfer)<tr>
            <td><strong>{{ $transfer->reference }}</strong><small>{{ $transfer->created_at->timezone($timezone)->format('d/m/Y H:i') }}</small></td>
            <td>{{ $transfer->parties_snapshot['sender']['first_name'] }} {{ $transfer->parties_snapshot['sender']['last_name'] }}</td>
            <td>{{ $transfer->sourceBranch->code }} → {{ $transfer->destinationBranch->code }}</td>
            <td>@money($transfer->amount_received) {{ $transfer->source_currency }}</td>
            <td>@money($transfer->fee_amount) {{ $transfer->source_currency }}<small>@percentage($transfer->fee_percentage) %</small></td>
            <td>@money($transfer->payout_amount) {{ $transfer->destination_currency }}</td>
            <td><span class="pill">{{ $transfer->status->label() }}</span></td>
            <td><x-action-link :href="route('transfers.show', $transfer)" icon="eye" label="Voir le transfert" /></td>
        </tr>@endforeach
    </x-listing>
</x-layouts.workspace>
