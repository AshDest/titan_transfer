<x-layouts.workspace title="Transferts">
    <x-page-heading title="Transferts" description="Retrouvez les opérations de votre agence." :create="auth()->user()->can('transfers.create') ? 'transfers.create' : null" button="Nouvelle opération" />
    <x-listing :records="$records" :headings="['Référence', 'Client', 'Trajet', 'Encaissé', 'À retirer', 'Statut', '']" placeholder="Rechercher une référence…">
        @foreach($records as $transfer)<tr>
            <td><strong>{{ $transfer->reference }}</strong><small>{{ $transfer->created_at->format('d/m/Y H:i') }}</small></td>
            <td>{{ $transfer->parties_snapshot['sender']['first_name'] }} {{ $transfer->parties_snapshot['sender']['last_name'] }}</td>
            <td>{{ $transfer->sourceBranch->code }} → {{ $transfer->destinationBranch->code }}</td>
            <td>{{ $transfer->amount_received }} {{ $transfer->source_currency }}</td>
            <td>{{ $transfer->payout_amount }} {{ $transfer->destination_currency }}</td>
            <td><span class="pill">{{ $transfer->status->label() }}</span></td>
            <td><a class="text-button" href="{{ route('transfers.show', $transfer) }}">Voir le transfert</a></td>
        </tr>@endforeach
    </x-listing>
</x-layouts.workspace>
