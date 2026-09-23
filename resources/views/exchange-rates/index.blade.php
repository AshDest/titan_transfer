<x-layouts.workspace title="Taux de change">
    <x-page-heading title="Taux de change" description="Consultez l’historique ou enregistrez une nouvelle version." :create="auth()->user()->can('create', \App\Models\ExchangeRate::class) ? 'exchange-rates.create' : null" button="Nouveau taux" />
    <x-listing :records="$records" :headings="['Conversion', 'Taux', 'Début (UTC)', 'Fin (UTC)', 'Configuration', 'Auteur']" :searchable="false">
        @foreach($records as $record)<tr><td>{{ $record->from_currency }} → {{ $record->to_currency }}</td><td><strong><span title="Taux exact : {{ $record->rate }}">@rate($record->rate)</span></strong></td><td>{{ $record->effective_from->format('d/m/Y H:i') }}</td><td>{{ $record->effective_until?->format('d/m/Y H:i') ?? 'Sans échéance' }}</td><td>{{ $record->active ? 'Activée' : 'Désactivée' }}</td><td>{{ $record->createdBy->name }}</td></tr>@endforeach
    </x-listing>
</x-layouts.workspace>
