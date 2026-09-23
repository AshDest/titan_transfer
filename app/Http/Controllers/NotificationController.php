<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate(['filter' => ['nullable', 'in:all,unread']]);
        $unreadOnly = $request->query('filter') === 'unread';
        $records = $request->user()->notifications()->visibleTo($request->user())
            ->when($unreadOnly, fn ($query) => $query->unread())->paginate(20)->withQueryString();

        return view('notifications.index', compact('records', 'unreadOnly'));
    }

    public function feed(Request $request): JsonResponse
    {
        $query = $request->user()->notifications()->visibleTo($request->user());
        $unreadCount = (clone $query)->unread()->count();
        $records = $query->limit(5)->get();

        return response()->json([
            'unread_count' => $unreadCount,
            'html' => view('notifications.items', ['records' => $records, 'compact' => true])->render(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        $record = $request->user()->notifications()->visibleTo($request->user())->with('subject')->findOrFail($notification);
        Gate::authorize('view', $record->subject);
        $record->markAsRead();

        return redirect()->to($record->destinationUrl());
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $request->user()->notifications()->visibleTo($request->user())->findOrFail($notification)->markAsRead();

        return redirect()->route('notifications.index')->with('status', 'Notification marquée comme lue.');
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->notifications()->visibleTo($request->user())->unread()->update(['read_at' => now()]);

        return redirect()->route('notifications.index')->with('status', 'Toutes vos notifications ont été marquées comme lues.');
    }
}
