<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Billing\ChangePlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Tenant-side controller for self-service plan change.
 *
 * The user-facing surface of the `plan-change` capability
 * (1.5G-buy-plan). Two write surfaces share a single mutation:
 *   - Tenant-side: this controller
 *   - Landlord-side: {@see \App\Http\Controllers\Landlord\ChangePlanController}
 *
 * Authorization is permission-based (`$user->can('change-plan')`),
 * not role-based — see §23.4 of the architecture doc for the
 * rationale. The mutation itself is delegated to
 * {@see ChangePlanService::applyPlanChange()}, which holds the
 * row lock and the same-plan guard so the write surface stays
 * thin and auth intent stays local to the controller.
 */
class ChangePlanController extends Controller
{
    /**
     * Render the change-plan page.
     *
     * The Inertia page receives every active plan (the React
     * component filters out the current one in the UI) plus the
     * tenant's current plan so it can render the "Current plan"
     * badge.
     */
    public function show(Request $request): InertiaResponse
    {
        abort_unless($request->user()?->can('change-plan'), 403);

        $tenant = Tenant::current();
        $currentPlan = $tenant?->subscription?->plan;

        return Inertia::render('billing/change-plan', [
            'plans' => Plan::query()->active()->orderBy('price_cents')->get(),
            'currentPlan' => $currentPlan,
        ]);
    }

    /**
     * Apply a plan change for the current tenant.
     *
     * Resolves the subscription from the `tenant` middleware
     * context (the URL does NOT carry the tenant id — the
     * middleware does), checks the requested plan is different
     * from the current one, then delegates to the shared service.
     */
    public function update(
        Request $request,
        ChangePlanService $service,
    ): RedirectResponse {
        abort_unless($request->user()?->can('change-plan'), 403);

        $tenant = Tenant::current();

        abort_unless($tenant?->subscription, 404, 'No subscription found for the current tenant.');

        $subscription = $tenant->subscription;

        $newPlanId = (int) $request->input('plan_id');

        abort_if(
            $newPlanId === 0,
            422,
            'A plan_id is required.',
        );

        $newPlan = Plan::query()->active()->findOrFail($newPlanId);

        abort_if(
            $subscription->plan_id === $newPlan->id,
            422,
            'You are already on this plan.',
        );

        $service->applyPlanChange($subscription, $newPlan);

        return to_route('billing.change-plan.show')
            ->with('success', "Your plan has been changed to {$newPlan->name}.");
    }
}
