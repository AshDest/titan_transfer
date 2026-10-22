<x-layouts.workspace title="Vue d’ensemble">
    <div class="overview-page">
        <div class="page-heading overview-heading">
            <h1>Tableau de bord</h1>
            <span class="overview-date">{{ now($user->branch?->timezone ?? 'UTC')->format('d/m/Y') }}</span>
        </div>

        <section class="overview-welcome" aria-labelledby="welcome-title">
            <div class="overview-welcome-copy">
                <span class="overview-eyebrow">TITAN TRANSFER</span>
                <h2 id="welcome-title">Bienvenue, {{ $user->name }}.</h2>
                <span class="overview-location"><x-icon name="branch" /> {{ $user->branch?->name ?? 'Administration' }}</span>
            </div>
            @can('transfers.create')
                <a class="primary-button compact-button" href="{{ route('transfers.create') }}"><x-icon name="plus" /> Nouvelle opération</a>
            @endcan
        </section>

        <section class="overview-stats" aria-label="Résumé de votre espace">
            <article class="overview-stat">
                <span class="summary-icon"><x-icon name="branch" /></span>
                <div><h2>Agences accessibles</h2><strong>{{ number_format($branches->total(), 0, ',', ' ') }}</strong></div>
            </article>
            @if($customerCount !== null)
                <article class="overview-stat">
                    <span class="summary-icon"><x-icon name="users" /></span>
                    <div><h2>Clients enregistrés</h2><strong>{{ number_format($customerCount, 0, ',', ' ') }}</strong></div>
                </article>
            @endif
            @if($beneficiaryCount !== null)
                <article class="overview-stat">
                    <span class="summary-icon"><x-icon name="user-plus" /></span>
                    <div><h2>Bénéficiaires</h2><strong>{{ number_format($beneficiaryCount, 0, ',', ' ') }}</strong></div>
                </article>
            @endif
        </section>

        @php($hasQuickLinks = $user->canAny(['transfers.view', 'cash.view', 'customers.view']) || $user->can('reviewAny', \App\Models\Transfer::class))
        <div @class(['overview-panels', 'overview-panels-single' => ! $hasQuickLinks])>
            <section class="branch-section overview-agencies" aria-labelledby="branches-title">
                <div class="section-heading">
                    <div class="overview-panel-title"><span class="summary-icon"><x-icon name="branch" /></span><h2 id="branches-title">Vos agences</h2></div>
                    @unless($user->isCashier())
                        @can('viewAny', \App\Models\Branch::class)<x-action-link :href="route('branches.index')" icon="arrow" label="Voir les agences" />@endcan
                    @endunless
                </div>
                <div class="table-scroll" role="region" aria-label="Liste des agences" tabindex="0">
                    <table>
                        <thead><tr><th>Agence</th><th>Localisation</th><th>Devise</th><th>Statut</th></tr></thead>
                        <tbody>
                            @forelse($branches as $branch)
                                <tr>
                                    <td><div class="branch-name"><span class="branch-code">{{ $branch->code }}</span><strong>{{ $branch->name }}</strong></div></td>
                                    <td>{{ $branch->city }}<small>{{ $branch->country }}</small></td>
                                    <td><span class="currency-chip">{{ $branch->default_currency }}</span></td>
                                    <td><span @class(['pill', 'muted-pill' => $branch->status === \App\Enums\RecordStatus::Inactive])>{{ $branch->status === \App\Enums\RecordStatus::Active ? 'Active' : 'Inactive' }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="4"><div class="empty-state"><x-icon name="branch" /><strong>Aucune agence dans votre périmètre.</strong></div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($branches->hasPages())<div class="listing-footer">{{ $branches->links() }}</div>@endif
            </section>

            @if($hasQuickLinks)
                <section class="branch-section overview-shortcuts" aria-labelledby="shortcuts-title">
                    <div class="section-heading"><h2 id="shortcuts-title">Accès rapides</h2><x-icon name="grid" /></div>
                    <nav class="overview-shortcut-list" aria-label="Accès rapides">
                        @can('reviewAny', \App\Models\Transfer::class)
                            <a class="overview-shortcut overview-shortcut-review" href="{{ route('transfers.index', ['status' => 'PENDING']) }}"><span class="summary-icon"><x-icon name="check" /></span><span>Transferts à valider</span><x-icon name="arrow" /></a>
                        @endcan
                        @can('transfers.view')
                            <a class="overview-shortcut" href="{{ route('transfers.index') }}"><span class="summary-icon"><x-icon name="exchange" /></span><span>Transferts</span><x-icon name="arrow" /></a>
                        @endcan
                        @can('cash.view')
                            <a class="overview-shortcut" href="{{ route('treasury.index') }}"><span class="summary-icon"><x-icon name="cash" /></span><span>Trésorerie</span><x-icon name="arrow" /></a>
                        @endcan
                        @can('customers.view')
                            <a class="overview-shortcut" href="{{ route('customers.index') }}"><span class="summary-icon"><x-icon name="users" /></span><span>Clients</span><x-icon name="arrow" /></a>
                        @endcan
                    </nav>
                </section>
            @endif
        </div>
    </div>
</x-layouts.workspace>
