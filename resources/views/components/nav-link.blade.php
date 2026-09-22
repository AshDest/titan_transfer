@props(['route' => null, 'icon' => 'grid', 'pending' => false])
@if ($pending)
    <span class="nav-link nav-pending" aria-disabled="true"><x-icon :name="$icon" /><span>{{ $slot }}</span><small>À venir</small></span>
@else
    <a href="{{ route($route) }}" @class(['nav-link', 'active' => request()->routeIs(str_replace('.index', '.*', $route))]) @if(request()->routeIs(str_replace('.index', '.*', $route))) aria-current="page" @endif><x-icon :name="$icon" /><span>{{ $slot }}</span></a>
@endif
