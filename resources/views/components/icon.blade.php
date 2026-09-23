@props(['name'])
<svg {{ $attributes->class(['icon']) }} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('bell')<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>@break
        @case('menu')<path d="M4 6h16M4 12h16M4 18h16"/>@break
        @case('plus')<path d="M12 4v16M4 12h16"/>@break
        @case('cash')<rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M5 9h1m12 6h1"/>@break
        @case('exchange')<path d="M3 7h17l-4-4m5 14H4l4 4M20 7l-4 4M4 17l4-4"/>@break
        @case('percent')<path d="m5 19 14-14"/><circle cx="7" cy="7" r="3"/><circle cx="17" cy="17" r="3"/>@break
        @case('report')<path d="M5 3h14v18H5zM9 8h6M9 12h6M9 16h4"/>@break
        @case('settings')<path d="M4 7h16M4 17h16"/><circle cx="8" cy="7" r="3"/><circle cx="16" cy="17" r="3"/>@break
        @case('arrow')<path d="M4 12h15m-6-6 6 6-6 6"/>@break
        @case('lock')<rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/>@break
        @case('mail')<rect x="3" y="5" width="18" height="14" rx="3"/><path d="m3 7 9 6 9-6"/>@break
        @case('eye')<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>@break
        @case('shield')<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>@break
        @case('grid')<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>@break
        @case('branch')<path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-5h6v5M9 8h.01M15 8h.01M9 12h.01M15 12h.01"/>@break
        @case('users')<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3m2-16a3 3 0 0 1 0 6m2 10v-3a6 6 0 0 0-2-4"/>@break
        @case('logout')<path d="M9 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4m6-12 4 4-4 4m-8-4h12"/>@break
        @case('check')<path d="m5 12 4 4L19 6"/>@break
        @case('help')<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 5 .5c0 2-2.5 2-2.5 4m0 3h.01"/>@break
    @endswitch
</svg>
