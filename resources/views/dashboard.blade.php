<x-layouts.workspace title="Vue d’ensemble">
                <div class="dashboard-heading"><div><span class="form-kicker">VUE D’ENSEMBLE</span><h1>Bienvenue, {{ $user->name }}.</h1><p>Retrouvez les informations de votre réseau Titan Transfer.</p></div>@can('transfers.create')<a class="primary-button compact-button" href="{{ route('transfers.create') }}"><x-icon name="plus" /> Nouvelle opération</a>@else<span class="pill"><span class="status-dot"></span> Session ouverte</span>@endcan</div>
                <section class="summary-grid" aria-label="Résumé de votre espace">
                    <article class="summary-card"><span class="summary-icon"><x-icon name="branch" /></span><p>Agences accessibles</p><strong>{{ $branches->total() }}</strong><small>Votre réseau opérationnel</small></article>
                    @if ($customerCount !== null)<article class="summary-card"><span class="summary-icon"><x-icon name="users" /></span><p>Clients enregistrés</p><strong>{{ $customerCount }}</strong><small>Dans votre périmètre d’accès</small></article>@endif
                    @if ($beneficiaryCount !== null)<article class="summary-card"><span class="summary-icon"><x-icon name="users" /></span><p>Bénéficiaires</p><strong>{{ $beneficiaryCount }}</strong><small>Dans votre périmètre d’accès</small></article>@endif
                </section>
                <section class="branch-section" aria-labelledby="branches-title">
                    <div class="section-heading"><div><h2 id="branches-title">Vos agences</h2><p>Les équipes et les devises de votre réseau.</p></div><x-icon name="branch" /></div>
                    <div class="table-scroll"><table><thead><tr><th>Agence</th><th>Localisation</th><th>Devise</th><th>Statut</th></tr></thead><tbody>
                    @forelse ($branches as $branch)
                        <tr><td><div class="branch-name"><span class="branch-code">{{ $branch->code }}</span><strong>{{ $branch->name }}</strong></div></td><td>{{ $branch->city }}<small>{{ $branch->country }}</small></td><td><span class="currency-chip">{{ $branch->default_currency }}</span></td><td><span class="pill {{ $branch->status === \App\Enums\RecordStatus::Inactive ? 'muted-pill' : '' }}">{{ $branch->status === \App\Enums\RecordStatus::Active ? 'Active' : 'Inactive' }}</span></td></tr>
                    @empty
                        <tr><td colspan="4">Aucune agence dans votre périmètre.</td></tr>
                    @endforelse
                    </tbody></table></div>
                    @if ($branches->hasPages())<div class="pagination">{{ $branches->links() }}</div>@endif
                </section>
</x-layouts.workspace>
