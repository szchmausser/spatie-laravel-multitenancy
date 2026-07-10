<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\PaymentNotification;
use Inertia\Inertia;

/**
 * Admin panel controller.
 *
 * Handles the landlord/admin panel landing page at /admin.
 * It is the admin's home — currently a generic placeholder
 * matching the regular dashboard, ready to host admin-specific
 * widgets in the future.
 *
 * Reads no tenant data; the dedicated tenant list lives at
 * /admin/tenants. Protected by EnsureUserIsAdmin middleware
 * and never enters tenant context (no 'tenant' middleware applied).
 */
class AdminPanelController extends Controller
{
    /**
     * Show the admin panel landing page.
     */
    public function index()
    {
        $user = auth()->user();
        $lastViewed = $user?->last_viewed_payment_notifications_at;

        $query = PaymentNotification::query();

        if ($lastViewed) {
            $query->where('created_at', '>', $lastViewed);
        }

        $unreadPaymentNotificationsCount = $query->count();

        return Inertia::render('landlord/admin-panel', [
            'unread_payment_notifications_count' => $unreadPaymentNotificationsCount,
        ]);
    }
}
