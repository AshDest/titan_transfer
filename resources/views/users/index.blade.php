<x-layouts.workspace title="Utilisateurs">
    <x-page-heading title="Utilisateurs" description="Consultez les comptes et leurs agences de rattachement." />
    <x-listing :records="$records" :headings="['Nom', 'Adresse e-mail', 'Agence', 'Rôles']" placeholder="Nom ou adresse e-mail">
        @foreach($records as $record)<tr><td><strong>{{ $record->name }}</strong></td><td>{{ $record->email }}</td><td>{{ $record->branch?->name ?? 'Sans agence' }}</td><td>{{ $record->roles->map(fn ($role) => \App\Enums\Role::tryFrom($role->name)?->label() ?? $role->name)->join(', ') ?: 'Aucun rôle' }}</td></tr>@endforeach
    </x-listing>
</x-layouts.workspace>
