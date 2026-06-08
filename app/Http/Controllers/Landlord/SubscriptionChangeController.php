<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Billing\PlanChangeController;
use App\Http\Controllers\Controller;
use App\Models\Landlord;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Billing\ChangePlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Landlord-side controller for plan change (admin backdoor).
 *
 * Bypasses the tenant-side `change-plan` permission because the
 * landlord database has no Spatie tables — see §23.1 of the
 * architecture doc. Authorization is delegated to the route
 * group's `EnsureUserIsAdmin` middleware; this controller trusts
 * that any caller is a {@see Landlord} instance.
 *
 * The mutation is shared with the tenant-side controller
 * ({@see PlanChangeController})
 * via {@see ChangePlanService::applyPlanChange()}, so the
 * same-plan guard has a single source of truth.
 */
class SubscriptionChangeController extends Controller
{
    /**
     * Apply a plan change to a tenant from the admin panel.
     *
     * Resolves the target subscription through the route's
     * `{tenant}` parameter (NOT through `Tenant::current()` — the
     * landlord never enters tenant context, so there is no
     * "current" tenant), then delegates to the shared service.
     *
     * The destination plan comes from the request body
     * (`plan_id`), matching the `assign` style on the existing
     * `SubscriptionController::assign`.
     */
    public function update(
        Request $request,
        Tenant $tenant,
        ChangePlanService $service,
    ): RedirectResponse {
        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
        ]);

        $plan = Plan::query()->findOrFail($validated['plan_id']);
        $subscription = $tenant->subscription()->firstOrFail();

        $service->applyPlanChange($subscription, $plan);

        return to_route('landlord.tenants.show', $tenant)
            ->with('success', "Plan changed to {$plan->name} for tenant {$tenant->name}.");
    }
}
