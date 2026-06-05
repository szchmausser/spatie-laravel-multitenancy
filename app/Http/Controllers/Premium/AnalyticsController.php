<?php

namespace App\Http\Controllers\Premium;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class AnalyticsController extends Controller
{
    /**
     * Display the premium analytics dashboard.
     *
     * This route is protected by the 'feature:premium-zone' middleware,
     * so only tenants with an active subscription that includes the
     * 'premium-zone' feature can access this page.
     */
    public function index()
    {
        return Inertia::render('premium/analytics/index', [
            // Analytics data would be fetched here
            'stats' => [
                'total_users' => 0,
                'active_sessions' => 0,
                'revenue' => 0,
            ],
        ]);
    }
}
