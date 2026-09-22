<x-layouts.workspace title="Frais de transfert">
    <x-page-heading title="Frais de transfert" description="Les règles de frais, leurs corridors et leurs seuils." :create="auth()->user()->can('fee-rules.manage') ? 'fee-rules.create' : null" button="Nouvelle règle" />
    <x-listing :records="$records" :headings="['Règle', 'Corridor', 'Pourcentage', 'Minimum', 'Maximum', 'Début (UTC)']" :searchable="false">
        @foreach($records as $record)<tr><td><strong>{{ $record->name }}</strong><small>{{ $record->active ? 'Activée' : 'Désactivée' }}</small></td><td>{{ $record->sourceBranch?->name ?? 'Toutes origines' }} → {{ $record->destinationBranch?->name ?? 'Toutes destinations' }}</td><td>{{ $record->percentage }} %</td><td>{{ $record->minimum_amount ?? '0.00' }} {{ $record->source_currency }}</td><td>{{ $record->maximum_amount ? $record->maximum_amount.' '.$record->source_currency : 'Sans plafond' }}</td><td>{{ $record->effective_from?->format('d/m/Y H:i') ?? 'Immédiat' }}</td></tr>@endforeach
    </x-listing>
</x-layouts.workspace>
