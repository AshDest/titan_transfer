@forelse($records as $notification)
    <article class="notification-item {{ $notification->read_at === null ? 'notification-unread' : '' }}">
        <span class="notification-indicator" aria-label="{{ $notification->read_at === null ? 'Non lue' : 'Lue' }}"></span>
        <div class="notification-copy">
            <form method="POST" action="{{ route('notifications.open', $notification->id) }}">
                @csrf
                <button class="notification-open"><strong>{{ $notification->data['title'] }}</strong><span>{{ $notification->data['message'] }}</span></button>
            </form>
            <p class="notification-meta">{{ $notification->data['actor'] }} · <time datetime="{{ $notification->created_at->toIso8601String() }}" title="{{ $notification->created_at->timezone(auth()->user()->branch?->timezone ?? 'UTC')->format('d/m/Y H:i') }}">{{ $notification->created_at->locale('fr')->diffForHumans() }}</time></p>
            @if(! $compact && $notification->read_at === null)
                <form method="POST" action="{{ route('notifications.read', $notification->id) }}">@csrf<button class="text-button">Marquer comme lue</button></form>
            @endif
        </div>
    </article>
@empty
    <div class="empty-state notification-empty"><x-icon name="bell" /><strong>Aucune notification</strong><span>Les prochaines opérations apparaîtront ici.</span></div>
@endforelse
