<x-layouts.workspace title="Frais de transfert">
    <x-page-heading title="Frais de transfert" :create="auth()->user()->can('create', \App\Models\TransferFeeRule::class) ? 'fee-rules.create' : null" button="Nouvelle tranche de frais" />
    @error('confirmation')<p class="notice field-error" role="alert">{{ $message }}</p>@enderror
    <x-listing :records="$records" :headings="['Règle', 'Corridor', 'Frais', 'Minimum inclus', 'Maximum inclus', 'Début (UTC)', 'Actions']" :searchable="false">
        @foreach($records as $record)<tr><td><strong>{{ $record->name }}</strong><small>{{ $record->active ? 'Activée' : 'Désactivée' }}</small></td><td>{{ $record->sourceBranch?->name ?? 'Toutes origines' }} → {{ $record->destinationBranch?->name ?? 'Toutes destinations' }}</td><td>@if($record->fee_type === \App\Enums\FeeType::Fixed)@money($record->fixed_amount) {{ $record->source_currency }}<small>Montant fixe</small>@else<span title="Pourcentage exact : {{ $record->percentage }} %">@percentage($record->percentage)</span> % @endif</td><td>@money($record->minimum_amount ?? '0.00') {{ $record->source_currency }}</td><td>@if($record->maximum_amount !== null)@money($record->maximum_amount) {{ $record->source_currency }}@else Sans plafond @endif</td><td>{{ $record->effective_from?->format('d/m/Y H:i') ?? 'Immédiat' }}</td><td><div class="row-actions">@can('create', \App\Models\TransferFeeRule::class)@if($record->active)<form method="POST" action="{{ route('fee-rules.deactivate', $record) }}">@csrf<button class="action-icon" aria-label="Désactiver cette tranche" title="Désactiver cette tranche"><x-icon name="pause" /></button></form>@endif @endcan
                @can('delete', $record)
                    <form method="POST" action="{{ route('fee-rules.destroy', $record) }}" data-confirm-delete="Supprimer cette règle de frais ? Les transferts existants seront conservés.">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="confirmation" value="1">
                        <button type="submit" class="action-icon action-icon-danger" aria-label="Supprimer la règle de frais" title="Supprimer la règle de frais"><x-icon name="trash" /></button>
                    </form>
                @endcan
            </div></td></tr>@endforeach
    </x-listing>
</x-layouts.workspace>
