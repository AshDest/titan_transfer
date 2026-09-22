@props(['records', 'headings', 'searchable' => true, 'placeholder' => 'Rechercher…'])
<section class="branch-section">
    @if($searchable)
        <form method="GET" action="{{ url()->current() }}" class="search-toolbar" role="search">
            <label class="sr-only" for="directory-search">Rechercher</label>
            <input id="directory-search" type="search" name="q" value="{{ is_string(request('q')) ? request('q') : '' }}" placeholder="{{ $placeholder }}" maxlength="100">
            <button class="secondary-button">Rechercher</button>
            @if(request()->filled('q'))<a href="{{ url()->current() }}" class="text-button">Effacer</a>@endif
        </form>
        @error('q')<p class="field-error">{{ $message }}</p>@enderror
    @endif
    <div class="table-scroll"><table><thead><tr>@foreach($headings as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead><tbody>
        @if($records->isEmpty())
            <tr><td colspan="{{ count($headings) }}"><div class="empty-state"><x-icon name="grid" /><strong>Aucun résultat</strong><span>{{ request()->filled('q') ? 'Essayez une autre recherche.' : 'Aucun enregistrement pour le moment.' }}</span></div></td></tr>
        @else
            {{ $slot }}
        @endif
    </tbody></table></div>
    <div class="listing-footer"><span>{{ $records->total() }} enregistrement(s)</span>{{ $records->links() }}</div>
</section>
