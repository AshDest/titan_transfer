<x-layouts.workspace title="Trésorerie">
    <x-page-heading title="Trésorerie" :create="auth()->user()->can('createWithdrawal', \App\Models\CashAccount::class) ? 'treasury.cash-outs.create' : null" button="Sortie de caisse" />
    <section class="branch-section">
        <h2>Soldes actuels par agence et devise</h2>
        <div class="table-scroll"><table>
            <thead><tr><th>Agence</th><th>Caisse</th><th>Devise</th><th>Solde disponible</th><th>Statut</th></tr></thead>
            <tbody>
                @forelse($balances as $balance)
                    <tr><td>{{ $balance['branch']->name }}</td><td>{{ $balance['account']?->name ?? 'Aucun mouvement' }}</td><td>{{ $balance['currency'] }}</td><td><strong>@money($balance['balance']) {{ $balance['currency'] }}</strong></td><td>{{ $balance['branch']->status === \App\Enums\RecordStatus::Active && ($balance['account'] === null || $balance['account']->status === \App\Enums\RecordStatus::Active) ? 'Active' : 'Inactive' }}</td></tr>
                @empty
                    <tr><td colspan="5">Aucune caisse pour cette sélection.</td></tr>
                @endforelse
            </tbody>
        </table></div>
    </section>
    <h2>Historique des mouvements</h2>
    <form method="GET" action="{{ route('treasury.index') }}" class="editor-form">
        <div class="form-grid">
            @if(auth()->user()->canViewAllCashAccounts())
                <x-field name="branch_id" label="Agence">
                    <option value="">Toutes les agences</option>
                    @foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) request('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>@endforeach
                </x-field>
            @endif
            <x-field name="type" label="Type de mouvement">
                <option value="">Tous les mouvements</option>
                @foreach(\App\Enums\CashMovementType::cases() as $type)<option value="{{ $type->value }}" @selected(request('type') === $type->value)>{{ $type->label() }}</option>@endforeach
            </x-field>
            <x-field name="date_from" label="Du" type="date" :value="request('date_from', '')" />
            <x-field name="date_to" label="Au (inclus)" type="date" :value="request('date_to', '')" />
            <x-field name="currency" label="Devise" maxlength="3" placeholder="USD" :value="request('currency', '')" />
            <x-field name="q" label="Bénéficiaire, motif ou référence du transfert" maxlength="100" :value="request('q', '')" />
        </div>
        <div class="form-actions"><a class="secondary-button" href="{{ route('treasury.index') }}">Réinitialiser</a><button class="primary-button compact-button">Filtrer</button></div>
    </form>
    <p class="field-hint">Heures de saisie : {{ $timezone }}.</p>
    <x-listing :records="$records" :headings="['N°', 'Date d’opération', 'Agence', 'Type', 'Montant', 'Bénéficiaire', 'Motif / Référence', 'Solde avant saisie', 'Solde après saisie', 'Enregistré par', 'Date de saisie']" :searchable="false">
        @foreach($records as $record)
            <tr>
                <td>{{ $record->id }}</td><td>{{ $record->occurred_on?->format('d/m/Y') }}</td><td>{{ $record->account->branch->name }}</td><td>{{ $record->type->label() }}</td>
                <td><strong>@money($record->amount) {{ $record->currency }}</strong></td><td>{{ $record->recipient_name ?? '—' }}</td>
                <td>{{ $record->description }}@if($record->transfer)<br>@can('view', $record->transfer)<a href="{{ route('transfers.show', $record->transfer) }}">{{ $record->transfer->reference }}</a>@else{{ $record->transfer->reference }}@endcan @endif</td>
                <td>@money($record->balance_before) {{ $record->currency }}</td><td>@money($record->balance_after) {{ $record->currency }}</td><td>{{ $record->createdBy->name }}</td><td>{{ $record->created_at->setTimezone($timezone)->format('d/m/Y H:i') }}</td>
            </tr>
        @endforeach
    </x-listing>
</x-layouts.workspace>
