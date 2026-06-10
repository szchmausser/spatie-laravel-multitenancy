<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * Display the notifications page.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $unread = $user->notifications()
            ->whereNull('read_at')
            ->latest()
            ->get();

        $read = $user->notifications()
            ->whereNotNull('read_at')
            ->latest()
            ->take(50)
            ->get();

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
        $notification = $request->user()
            ->notifications()
            ->where('id', $notification)
            ->firstOrFail();

        $notification->markAsRead();

        return redirect()->back();
    }

    /**
     * Mark all notifications as read for the authenticated user.
     */
    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->each->markAsRead();

        return redirect()->back();
    }
}
