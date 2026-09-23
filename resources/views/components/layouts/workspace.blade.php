@props(['title'])
@php($user = auth()->user())
<x-layouts.base :title="$title">
    <div class="workspace">
        <aside class="sidebar" id="workspace-sidebar">
            <a href="{{ route('dashboard') }}" aria-label="Titan Transfer — Accueil"><x-brand inverse /></a>
            <nav aria-label="Navigation principale" class="workspace-nav">
                @can('transfers.create')<a href="{{ route('transfers.create') }}" class="new-operation"><x-icon name="plus" /> Nouvelle opération</a>@endcan
                <div class="sidebar-label">OPÉRATIONS</div>
                <x-nav-link route="dashboard" icon="grid">Vue d’ensemble</x-nav-link>
                @can('transfers.view')<x-nav-link route="transfers.index" icon="arrow">Transferts</x-nav-link>@endcan
                @can('customers.view')<x-nav-link route="customers.index" icon="users">Clients</x-nav-link>@endcan
                @can('beneficiaries.view')<x-nav-link route="beneficiaries.index" icon="users">Bénéficiaires</x-nav-link>@endcan
                @can('cash.view')<x-nav-link icon="cash" pending>Trésorerie</x-nav-link>@endcan
                @canany(['exchange-rates.view', 'fee-rules.view', 'reports.view'])
                    <div class="sidebar-label">FINANCE</div>
                    @can('exchange-rates.view')<x-nav-link route="exchange-rates.index" icon="exchange">Taux de change</x-nav-link>@endcan
                    @can('fee-rules.view')<x-nav-link route="fee-rules.index" icon="percent">Frais de transfert</x-nav-link>@endcan
                    @can('reports.view')<x-nav-link icon="report" pending>Rapports</x-nav-link>@endcan
                @endcanany
                @canany(['branches.view', 'users.manage', 'roles.manage', 'audit.view', 'settings.manage'])
                    <div class="sidebar-label">ADMINISTRATION</div>
                    @can('branches.view')<x-nav-link route="branches.index" icon="branch">Agences</x-nav-link>@endcan
                    @can('users.manage')<x-nav-link route="users.index" icon="users">Utilisateurs</x-nav-link>@endcan
                    @can('roles.manage')<x-nav-link route="roles.index" icon="shield">Rôles et permissions</x-nav-link>@endcan
                    @can('audit.view')<x-nav-link icon="report" pending>Journal d’audit</x-nav-link>@endcan
                    @can('settings.manage')<x-nav-link icon="settings" pending>Paramètres</x-nav-link>@endcan
                @endcanany
            </nav>
            <div class="sidebar-bottom"><span class="avatar">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span><div><strong>{{ $user->name }}</strong><span>{{ $user->branch?->name ?? 'Administration' }}</span></div></div>
        </aside>
        <div class="workspace-main">
            <header class="workspace-header">
                <div class="header-title"><button type="button" class="menu-toggle" data-menu-toggle aria-label="Afficher le menu" aria-controls="workspace-sidebar" aria-expanded="false"><x-icon name="menu" /></button><span>{{ $title }}</span></div>
                <div class="header-actions">
                    <x-notification-menu />
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout-button" aria-label="Se déconnecter"><x-icon name="logout" /> <span>Se déconnecter</span></button></form>
                </div>
            </header>
            <main class="dashboard-content">
                @if(session('status'))<div class="notice notice-success page-notice" role="status"><x-icon name="check" />{{ session('status') }}</div>@endif
                {{ $slot }}
            </main>
        </div>
    </div>
</x-layouts.base>
