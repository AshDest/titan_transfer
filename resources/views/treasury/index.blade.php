<x-layouts.workspace title="Trésorerie">
    <div class="page-heading">
        <h1>Trésorerie</h1>
        <div class="treasury-heading-actions">
            @can('createFunding', \App\Models\CashAccount::class)<a class="primary-button compact-button" href="{{ route('treasury.cash-ins.create') }}"><x-icon name="plus" /> Approvisionner</a>@endcan
            @can('createWithdrawal', \App\Models\CashAccount::class)<a class="secondary-button" href="{{ route('treasury.cash-outs.create') }}">Sortie de caisse</a>@endcan
        </div>
    </div>
    <div class="treasury-page">
        <section aria-labelledby="treasury-balances-title">
            <div class="treasury-section-heading">
                <h2 id="treasury-balances-title">Soldes actuels</h2>
                <span class="treasury-caption">Par agence et devise</span>
            </div>
            <div class="treasury-balances">
                @forelse($balances as $balance)
                    @php($isActive = $balance['branch']->status === \App\Enums\RecordStatus::Active && ($balance['account'] === null || $balance['account']->status === \App\Enums\RecordStatus::Active))
                    <article @class(['treasury-balance-card', 'treasury-balance-inactive' => ! $isActive])>
                        <div class="treasury-balance-heading">
                            <span class="summary-icon"><x-icon name="branch" /></span>
                            <span @class(['pill', 'muted-pill' => ! $isActive])>{{ $isActive ? 'Active' : 'Inactive' }}</span>
                        </div>
                        <h3>{{ $balance['branch']->name }}</h3>
                        <p class="treasury-caption">{{ $balance['account']?->name ?? 'Aucun mouvement' }}</p>
                        <div class="treasury-balance-amount"><span>Solde disponible</span><strong>@money($balance['balance']) {{ $balance['currency'] }}</strong></div>
                    </article>
                @empty
                    <div class="branch-section treasury-balances-empty"><div class="empty-state"><x-icon name="cash" /><strong>Aucune caisse pour cette sélection.</strong></div></div>
                @endforelse
            </div>
        </section>

        <section class="treasury-history" aria-labelledby="treasury-history-title">
            <div class="treasury-section-heading">
                <h2 id="treasury-history-title">Historique des mouvements</h2>
                <span class="treasury-caption">{{ $records->total() }} mouvement(s)</span>
            </div>
            <form method="GET" action="{{ route('treasury.index') }}" class="editor-form treasury-filters" aria-label="Filtrer les mouvements">
                <div class="treasury-filter-grid">
                    <x-field name="q" label="Rechercher" placeholder="Personne, motif, référence…" maxlength="100" :value="request('q', '')" />
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
                    <x-field name="currency" label="Devise" maxlength="3" placeholder="Toutes les devises" :value="request('currency', '')" />
                </div>
                <div class="treasury-filter-actions">
                    <a class="text-button" href="{{ route('treasury.index') }}">Réinitialiser</a>
                    <button class="primary-button compact-button"><x-icon name="settings" /> Filtrer</button>
                </div>
            </form>
            <div class="treasury-ledger">
                <x-listing :records="$records" :headings="['Opération', 'Agence', 'Personne / Motif', 'Montant', 'Solde après saisie', 'Détails']" :searchable="false">
                    @foreach($records as $record)
                        <tr>
                            <td>
                                <strong>{{ $record->occurred_on?->format('d/m/Y') }}</strong>
                                <small>N° {{ $record->id }}</small>
                                <span @class(['treasury-movement-type', 'treasury-movement-out' => in_array($record->type, [\App\Enums\CashMovementType::Payout, \App\Enums\CashMovementType::CashOut]), 'treasury-movement-in' => in_array($record->type, [\App\Enums\CashMovementType::Deposit, \App\Enums\CashMovementType::CashIn])])>{{ $record->type->label() }}</span>
                            </td>
                            <td class="treasury-agency-cell">{{ $record->account->branch->name }}</td>
                            <td class="treasury-purpose-cell">
                                @if($record->recipient_name)<strong>{{ $record->recipient_name }}</strong>@endif
                                @if($record->provider_name)<strong>{{ $record->provider_name }}</strong><small>Apporteur des fonds</small>@endif
                                <p>{{ $record->description }}</p>
                                @if($record->transfer)
                                    @can('view', $record->transfer)<a class="treasury-reference" href="{{ route('transfers.show', $record->transfer) }}">{{ $record->transfer->reference }} <x-icon name="arrow" /></a>@else<span class="treasury-reference">{{ $record->transfer->reference }}</span>@endcan
                                @endif
                            </td>
                            <td class="treasury-money"><strong>@money($record->amount) {{ $record->currency }}</strong></td>
                            <td class="treasury-money">@money($record->balance_after) {{ $record->currency }}</td>
                            <td>
                                <details class="treasury-movement-details">
                                    <summary class="action-icon" aria-label="Détails du mouvement {{ $record->id }}" title="Détails du mouvement {{ $record->id }}"><x-icon name="eye" /></summary>
                                    <dl>
                                        <div><dt>Solde avant saisie</dt><dd>@money($record->balance_before) {{ $record->currency }}</dd></div>
                                        <div><dt>Enregistré par</dt><dd>{{ $record->createdBy->name }}</dd></div>
                                        <div><dt>Date de saisie ({{ $timezone }})</dt><dd>{{ $record->created_at->setTimezone($timezone)->format('d/m/Y H:i') }}</dd></div>
                                    </dl>
                                </details>
                            </td>
                        </tr>
                    @endforeach
                </x-listing>
            </div>
        </section>
    </div>
</x-layouts.workspace>
