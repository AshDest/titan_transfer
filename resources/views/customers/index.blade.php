<x-layouts.workspace title="Clients">
    <x-page-heading title="Clients" description="Retrouvez vos expéditeurs et leurs coordonnées." :create="auth()->user()->can('customers.create') ? 'customers.create' : null" button="Nouveau client" />
    <x-listing :records="$records" :headings="['Client', 'Numéro', 'Téléphone', 'Agence', 'Statut', '']" placeholder="Nom, téléphone ou numéro client">
        @foreach($records as $record)<tr><td><strong>{{ $record->first_name }} {{ $record->last_name }}</strong><small>{{ $record->country }}</small></td><td>{{ $record->customer_number }}</td><td>{{ $record->phone }}</td><td>{{ $record->branch->name }}</td><td>{{ $record->status === \App\Enums\RecordStatus::Active ? 'Actif' : 'Inactif' }}</td><td>@if($record->status === \App\Enums\RecordStatus::Active)@can('beneficiaries.create')<a class="text-button" href="{{ route('beneficiaries.create', ['customer' => $record->customer_number]) }}">Ajouter un bénéficiaire</a>@endcan @endif</td></tr>@endforeach
    </x-listing>
</x-layouts.workspace>
