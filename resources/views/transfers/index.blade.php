<x-layouts.workspace title="Transferts">
    <x-page-heading title="Transferts" description="Retrouvez les opérations de votre agence." :create="auth()->user()->can('transfers.create') ? 'transfers.create' : null" button="Nouvelle opération" />
    <form method="GET" action="{{ route('transfers.index') }}" class="transfer-filters">
        <label for="transfer-search" class="sr-only">Référence</label><input id="transfer-search" name="q" type="search" placeholder="Rechercher une référence…" maxlength="100" value="{{ is_string(request('q')) ? request('q') : '' }}" class="form-control">
        <label for="transfer-status" class="sr-only">Statut</label><select id="transfer-status" name="status" class="form-control"><option value="">Tous les statuts</option>@foreach(\App\Enums\TransferStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>@endforeach</select>
        <button class="secondary-button">Filtrer</button>
        @can('reviewAny', \App\Models\Transfer::class)<a class="text-button" href="{{ route('transfers.index', ['status' => 'PENDING']) }}">À valider</a>@endcan
    </form>
    <x-listing :searchable="false" :records="$records" :headings="['Référence', 'Client', 'Trajet', 'Encaissé', 'À retirer', 'Statut', '']" placeholder="Rechercher une référence…">
        @foreach($records as $transfer)<tr>
            <td><strong>{{ $transfer->reference }}</strong><small>{{ $transfer->created_at->format('d/m/Y H:i') }}</small></td>
            <td>{{ $transfer->parties_snapshot['sender']['first_name'] }} {{ $transfer->parties_snapshot['sender']['last_name'] }}</td>
            <td>{{ $transfer->sourceBranch->code }} → {{ $transfer->destinationBranch->code }}</td>
            <td>@money($transfer->amount_received) {{ $transfer->source_currency }}</td>
            <td>@money($transfer->payout_amount) {{ $transfer->destination_currency }}</td>
            <td><span class="pill">{{ $transfer->status->label() }}</span></td>
            <td><a class="text-button" href="{{ route('transfers.show', $transfer) }}">Voir le transfert</a></td>
        </tr>@endforeach
    </x-listing>
</x-layouts.workspace>
