<x-layouts.workspace title="Agences">
    <x-page-heading title="Agences" description="Votre réseau, ses devises et ses fuseaux horaires." :create="auth()->user()->can('create', \App\Models\Branch::class) ? 'branches.create' : null" button="Nouvelle agence" />
    <x-listing :records="$records" :headings="['Agence', 'Ville', 'Pays', 'Devise', 'Fuseau horaire', 'Statut']" placeholder="Nom, code ou ville">
        @foreach($records as $record)<tr><td><div class="branch-name"><span class="branch-code">{{ $record->code }}</span><strong>{{ $record->name }}</strong></div></td><td>{{ $record->city }}</td><td>{{ $record->country }}</td><td>{{ $record->default_currency }}</td><td>{{ $record->timezone }}</td><td>{{ $record->status === \App\Enums\RecordStatus::Active ? 'Active' : 'Inactive' }}</td></tr>@endforeach
    </x-listing>
</x-layouts.workspace>
