<x-layouts.workspace title="Revenus des opérations">
    <div class="page-heading">
        <div><h1>Revenus des opérations</h1><p>Frais des transferts validés · dates d’encaissement de chaque agence.</p></div>
        <a class="secondary-button" href="{{ route('fee-revenue.export', $filters) }}"><x-icon name="report" /> Exporter CSV</a>
    </div>
    <div class="treasury-page">
        <form method="GET" action="{{ route('fee-revenue.index') }}" class="editor-form treasury-filters fee-revenue-filters">
            <div class="treasury-filter-grid">
                <x-field name="date_from" label="Du" type="date" required :value="$filters['date_from']" />
                <x-field name="date_to" label="Au (inclus)" type="date" required :value="$filters['date_to']" />
                @if(auth()->user()->canViewAllCashAccounts())
                    <x-field name="branch_id" label="Agence"><option value="">Toutes les agences</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $branch->id)>{{ $branch->name }}</option>@endforeach</x-field>
                @endif
                <x-field name="currency" label="Devise" maxlength="3" placeholder="Toutes les devises" :value="$filters['currency'] ?? ''" />
            </div>
            <div class="treasury-filter-actions"><a class="text-button" href="{{ route('fee-revenue.index') }}">Réinitialiser</a><button class="primary-button compact-button">Filtrer</button></div>
        </form>
        <section aria-label="Frais par agence et devise">
            <div class="treasury-balances">
                @forelse($totals as $total)
                    <article class="treasury-balance-card fee-revenue-card">
                        <div class="treasury-balance-heading"><span class="summary-icon"><x-icon name="percent" /></span><span class="currency-chip">{{ $total['account']->currency }}</span></div>
                        <h2>{{ $total['account']->branch->name }}</h2><p class="treasury-caption">{{ $total['count'] }} opération(s)</p>
                        <dl>
                            <div><dt>Frais générés</dt><dd>@money($total['fees'])</dd></div>
                            <div><dt>Déjà retirés</dt><dd>@money($total['withdrawn'])</dd></div>
                            <div class="fee-revenue-remaining"><dt>Frais restants</dt><dd>@money($total['available']) {{ $total['account']->currency }}</dd></div>
                        </dl>
                        @can('withdrawFees', $total['account'])
                            @if(\Brick\Math\BigDecimal::of($total['available'])->isGreaterThan(0))
                                <a class="secondary-button" href="{{ route('fee-revenue.withdrawals.create', [...$filters, 'cash_account_id' => $total['account']->id]) }}"><x-icon name="cash" /> Retirer des frais</a>
                            @endif
                        @endcan
                    </article>
                @empty
                    <div class="branch-section treasury-balances-empty"><div class="empty-state"><x-icon name="report" /><strong>Aucun frais pour cette période.</strong></div></div>
                @endforelse
            </div>
        </section>
        <section>
            <div class="treasury-section-heading"><h2>Détail des opérations</h2><span class="treasury-caption">{{ $records->total() }} opération(s)</span></div>
            <x-listing :records="$records" :headings="['Référence', 'Encaissement', 'Agence', 'Statut', 'Devise', 'Frais générés', 'Déjà retirés', 'Frais restants']" :searchable="false">
                @foreach($records as $record)
                    @php($amounts = $revenue->amounts($record))
                    <tr><td>@can('view', $record)<a class="text-button" href="{{ route('transfers.show', $record) }}">{{ $record->reference }}</a>@else{{ $record->reference }}@endcan</td><td>{{ $record->depositMovement->occurred_on->format('d/m/Y') }}</td><td>{{ $record->sourceBranch->name }}</td><td>{{ $record->status->label() }}</td><td>{{ $record->source_currency }}</td><td>@money($amounts['fees'])</td><td>@money($amounts['withdrawn'])</td><td><strong>@money($amounts['available'])</strong></td></tr>
                @endforeach
            </x-listing>
        </section>
        <section>
            <div class="treasury-section-heading"><h2>Historique des retraits</h2><span class="treasury-caption">Périodes recoupant votre sélection</span></div>
            <x-listing :records="$withdrawals" :headings="['Retrait', 'Période des frais', 'Agence', 'Bénéficiaire', 'Montant du retrait', 'Enregistré par']" :searchable="false">
                @foreach($withdrawals as $withdrawal)
                    <tr><td>N° {{ $withdrawal->movement->id }}<small>{{ $withdrawal->movement->occurred_on->format('d/m/Y') }}</small></td><td>{{ \Carbon\CarbonImmutable::parse($withdrawal->period_from)->format('d/m/Y') }} — {{ \Carbon\CarbonImmutable::parse($withdrawal->period_to)->format('d/m/Y') }}</td><td>{{ $withdrawal->movement->account->branch->name }}</td><td>{{ $withdrawal->movement->recipient_name }}</td><td><strong>@money($withdrawal->movement->amount) {{ $withdrawal->movement->currency }}</strong></td><td>{{ $withdrawal->movement->createdBy->name }}</td></tr>
                @endforeach
            </x-listing>
        </section>
    </div>
</x-layouts.workspace>
