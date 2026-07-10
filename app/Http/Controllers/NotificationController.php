<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * Display the notifications page.
     */
    public function index(Request $request): Response
    {
        $unread = $this->unreadNotifications($request);
        $read = $this->readNotifications($request);

        return Inertia::render('notifications/index', [
            'unread' => $unread,
            'read' => $read,
        ]);
    }

    /**
     * Mark a single notification as read.
     *
     * We use the user's notifications() relationship instead of route model
     * binding because DatabaseNotification resolves against the default
     * (landlord) connection, but notifications live on the tenant connection.
     * The relationship inherits the correct connection from the User model.
     */
    public function update(Request $request, string $notification): RedirectResponse
    {
        $request->user()
            ->notifications()
            ->where('id', $notification)
            ->firstOrFail()
            ->markAsRead();

        return redirect()->back();
    }

    /**
     * Mark all notifications as read for the authenticated user.
     *
     * Returns the notifications page directly instead of a redirect so
     * Inertia refreshes all shared props (including the sidebar badge
     * unread count) in a single response, avoiding stale badge state
     * on client-side navigation.
     */
    public function markAllRead(Request $request): Response
    {
        $request->user()->unreadNotifications->each->markAsRead();

        $unread = $this->unreadNotifications($request);
        $read = $this->readNotifications($request);

        return Inertia::render('notifications/index', [
            'unread' => $unread,
            'read' => $read,
        ]);
    }

    /**
     * Load unread notifications for the authenticated user.
     *
     * @return Collection<int, DatabaseNotification>
     */
    private function unreadNotifications(Request $request): Collection
    {
        return $request->user()
            ->notifications()
            ->whereNull('read_at')
            ->latest()
            ->get();
    }

    /**
     * Load read notifications for the authenticated user (latest 50).
     *
     * @return Collection<int, DatabaseNotification>
     */
    private function readNotifications(Request $request): Collection
    {
        return $request->user()
            ->notifications()
            ->whereNotNull('read_at')
            ->latest()
            ->take(50)
            ->get();
    }
}
