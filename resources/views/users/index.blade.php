<x-layouts.workspace title="Utilisateurs">
    <x-page-heading title="Utilisateurs" description="Les comptes, leurs rôles et leurs agences de rattachement." :create="auth()->user()->can('create', \App\Models\User::class) ? 'users.create' : null" button="Nouvel utilisateur" />
    <x-listing :records="$records" :headings="['Nom', 'Adresse e-mail', 'Téléphone', 'Agence', 'Rôles']" placeholder="Nom, e-mail ou téléphone">
        @foreach($records as $record)<tr><td><strong>{{ $record->name }}</strong></td><td>{{ $record->email }}</td><td>{{ $record->phone ?? 'Non renseigné' }}@can('updatePhone', $record)<br><a href="{{ route('users.phone.edit', $record) }}">Modifier le téléphone</a>@endcan</td><td>{{ $record->branch?->name ?? 'Sans agence' }}</td><td>{{ $record->roles->map(fn ($role) => \App\Enums\Role::tryFrom($role->name)?->label() ?? $role->name)->join(', ') ?: 'Aucun rôle' }}</td></tr>@endforeach
    </x-listing>
</x-layouts.workspace>
