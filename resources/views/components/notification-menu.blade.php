<details class="notification-menu" data-notification-menu data-feed-url="{{ route('notifications.feed') }}">
    <summary class="notification-bell" aria-label="Notifications : {{ $unreadCount }} non lues" data-notification-toggle>
        <x-icon name="bell" />
        <span class="notification-badge" data-unread-badge @if($unreadCount === 0) hidden @endif>{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
    </summary>
    <section class="notification-panel" aria-label="Dernières notifications">
        <div class="notification-panel-heading"><strong>Notifications</strong><a class="text-button" href="{{ route('notifications.index') }}">Tout voir</a></div>
        <p class="notification-refresh" data-notification-refresh role="status">Actualisation automatique</p>
        <div data-notification-preview>@include('notifications.items', ['compact' => true])</div>
        <div class="notification-panel-footer"><a href="{{ route('notifications.index', ['filter' => 'unread']) }}">Voir les notifications non lues</a></div>
    </section>
    <span class="sr-only" aria-live="polite" data-notification-announcement></span>
</details>
