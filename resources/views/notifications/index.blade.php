<x-layouts.workspace title="Notifications">
    <x-page-heading title="Notifications" description="L’activité de votre réseau, selon vos droits d’accès." />
    <section class="branch-section notifications-inbox">
        <div class="notification-toolbar">
            <nav class="notification-filters" aria-label="Filtrer les notifications">
                <a href="{{ route('notifications.index') }}" @if(! $unreadOnly) aria-current="page" @endif>Toutes</a>
                <a href="{{ route('notifications.index', ['filter' => 'unread']) }}" @if($unreadOnly) aria-current="page" @endif>Non lues</a>
            </nav>
            <div class="notification-toolbar-actions">
                <a class="secondary-button" href="{{ route('notifications.index', $unreadOnly ? ['filter' => 'unread'] : []) }}">Actualiser</a>
                <form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button class="secondary-button"><x-icon name="check" /> Tout marquer comme lu</button></form>
            </div>
        </div>
        @include('notifications.items', ['compact' => false])
        <div class="listing-footer"><span>{{ $records->total() }} notification(s)</span>{{ $records->links() }}</div>
    </section>
</x-layouts.workspace>
