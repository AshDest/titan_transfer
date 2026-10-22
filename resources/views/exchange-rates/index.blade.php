<x-layouts.workspace title="Taux de change">
    <x-page-heading title="Taux de change" :create="auth()->user()->can('create', \App\Models\ExchangeRate::class) ? 'exchange-rates.create' : null" button="Nouveau taux" />
    @error('activation')<p class="notice field-error" role="alert">{{ $message }}</p>@enderror
    <x-listing :records="$records" :headings="['Conversion', 'Taux', 'Début (UTC)', 'Fin (UTC)', 'Configuration', 'Auteur', 'Actions']" :searchable="false">
        @foreach($records as $record)
            <tr>
                <td>{{ $record->from_currency }} → {{ $record->to_currency }}</td>
                <td><strong><span title="Taux exact : {{ $record->rate }}">@rate($record->rate)</span></strong></td>
                <td>{{ $record->effective_from->format('d/m/Y H:i') }}</td>
                <td>{{ $record->effective_until?->format('d/m/Y H:i') ?? 'Sans échéance' }}</td>
                <td>{{ $record->active ? 'Activée' : 'Désactivée' }}</td>
                <td>{{ $record->createdBy->name }}</td>
                <td><div class="row-actions">
                    @can('update', $record)<x-action-link :href="route('exchange-rates.edit', $record)" icon="edit" label="Modifier le taux" />@endcan
                    @can('activate', $record)
                        <form method="POST" action="{{ route('exchange-rates.activate', $record) }}">
                            @csrf
                            <button type="submit" class="action-icon" title="Activer uniquement ce taux pour cette paire de devises" aria-label="Activer uniquement ce taux pour cette paire de devises"><x-icon name="power" /></button>
                        </form>
                        @if($record->active)
                            <form method="POST" action="{{ route('exchange-rates.deactivate', $record) }}">
                                @csrf
                                <button type="submit" class="action-icon" title="Désactiver le taux" aria-label="Désactiver le taux"><x-icon name="pause" /></button>
                            </form>
                        @endif
                    @endcan
                    @can('delete', $record)<x-action-link :href="route('exchange-rates.edit', $record).'#suppression'" icon="trash" label="Supprimer le taux" danger />@endcan
                </div></td>
            </tr>
        @endforeach
    </x-listing>
</x-layouts.workspace>
