<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Response as InertiaResponse;

class AlertController extends Controller
{
    /**
     * Display a paginated, filterable list of system alerts.
     *
     * Returns notifications where data->>'category' = 'system', ordered by
     * creation date descending. Supports filtering by severity, read status,
     * and date range. Invalid severity values are silently ignored.
     */
    public function index(Request $request): InertiaResponse
    {
        $query = Auth::user()->notifications()
            ->whereRaw("data::json->>'category' = ?", ['system']);

        // Filter by severity (comma-separated CSV), only allowing valid values
        if ($request->filled('severity')) {
            $severities = explode(',', $request->severity);
            $allowed = ['critical', 'warning', 'info'];
            $validSeverities = array_intersect($severities, $allowed);

            if (! empty($validSeverities)) {
                $query->where(function ($q) use ($validSeverities) {
                    foreach ($validSeverities as $sev) {
                        $q->orWhereRaw("data::json->>'severity' = ?", [trim($sev)]);
                    }
                });
            }
        }

        // Filter by read/unread
        if ($request->filled('read')) {
            $read = filter_var($request->read, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($read === true) {
                $query->whereNotNull('read_at');
            } elseif ($read === false) {
                $query->whereNull('read_at');
            }
        }

        // Date range
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $alerts = $query->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        return inertia('landlord/alerts', [
            'alerts' => $alerts,
            'filters' => $request->only(['severity', 'read', 'from', 'to']),
        ]);
    }

    /**
     * Mark a system alert as read.
     *
     * Uses the user's notifications() relationship to scope the query,
     * ensuring the notification belongs to the authenticated user and
     * has category = 'system'. Returns 404 if not found.
     * Returns the alerts page directly with fresh shared props instead of
     * redirecting, to avoid stale Inertia shared props on the dashboard.
     */
    public function read(Request $request, string $notification): InertiaResponse
    {
        $notification = $request->user()
            ->notifications()
            ->whereRaw("data::json->>'category' = ?", ['system'])
            ->where('id', $notification)
            ->firstOrFail();

        $notification->markAsRead();

        // Re-run the index query to return fresh page + shared props
        return $this->index($request);
    }
}
