<x-layouts.workspace title="Bénéficiaires">
    <x-page-heading title="Bénéficiaires" :create="auth()->user()->can('beneficiaries.create') ? 'beneficiaries.create' : null" button="Nouveau bénéficiaire" />
    <x-listing :records="$records" :headings="['Bénéficiaire', 'Téléphone', 'Pays', 'Client associé', 'Agence']" placeholder="Nom ou téléphone">
        @foreach($records as $record)<tr><td><strong>{{ $record->first_name }} {{ $record->last_name }}</strong></td><td>{{ $record->phone }}</td><td>{{ $record->country }}</td><td>{{ $record->customer ? $record->customer->first_name.' '.$record->customer->last_name : '—' }}</td><td>{{ $record->branch->name }}</td></tr>@endforeach
    </x-listing>
</x-layouts.workspace>
