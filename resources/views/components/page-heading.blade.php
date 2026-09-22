@props(['title', 'description' => null, 'create' => null, 'button' => 'Ajouter'])
<div class="page-heading">
    <div><h1>{{ $title }}</h1>@if($description)<p>{{ $description }}</p>@endif</div>
    @if($create)<a class="primary-button compact-button" href="{{ route($create) }}"><x-icon name="plus" />{{ $button }}</a>@endif
</div>
