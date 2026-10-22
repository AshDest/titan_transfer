<x-layouts.workspace title="Rôles et permissions">
    <x-page-heading title="Rôles et permissions" />
    <div class="role-grid">@foreach($records as $role)<article class="role-card"><h2>{{ \App\Enums\Role::tryFrom($role->name)?->label() ?? $role->name }}</h2><p>{{ $role->permissions->count() }} autorisations</p><ul>@foreach($role->permissions->sortBy('name') as $permission)<li><x-icon name="check" />{{ \App\Enums\Permission::tryFrom($permission->name)?->label() ?? $permission->name }}</li>@endforeach</ul></article>@endforeach</div>
    <div class="pagination">{{ $records->links() }}</div>
</x-layouts.workspace>
