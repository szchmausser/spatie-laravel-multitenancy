<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Tenant-side controller for viewing subscription history.
 *
 * Read-only surface for the tenant's own subscription event log.
 * Uses the same query pattern as
 * {@see \App\Http\Controllers\Landlord\SubscriptionHistoryController}
 * but scoped to the current tenant via {@see Tenant::current()}.
 *
 * Authorization is permission-based (`$user->can('change-plan')`),
 * matching the same gate used by {@see PlanChangeController}.
 */
class SubscriptionHistoryController extends Controller
{
    /**
     * Display paginated subscription history for the current tenant.
     *
     * The history is ordered by most recent first and paginated at
     * 20 entries per page. The Inertia page component receives the
     * paginated result directly — no transformation needed.
     */
    public function index(Request $request): InertiaResponse
    {
        abort_unless($request->user()?->can('change-plan'), 403);

        $tenant = Tenant::current();
        abort_unless($tenant, 404);

        $history = SubscriptionHistory::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return Inertia::render('billing/history', [
            'tenant' => $tenant,
            'history' => $history,
        ]);
    }
}
