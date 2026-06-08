<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Subscription;

/**
 * Shared mutation that backs the two write surfaces for plan change:
 *   - Tenant-side: Billing\ChangePlanController
 *   - Landlord-side: Landlord\ChangePlanController
 *
 * The same-plan guard and the `ends_at` reset live HERE. Controllers
 * are thin auth + resolution wrappers; both call
 * {@see applyPlanChange()} with the target subscription and the
 * destination plan.
 *
 * Authorization diverges at the controller layer (`Gate::allows`
 * for tenants, `EnsureUserIsAdmin` for landlords). The mutation
 * itself is identical for both paths, which is why the two
 * controllers can share a single service.
 */
class ChangePlanService
{
    /**
     * Apply a plan change to the given subscription.
     *
     *  - Refuses to apply the change when the subscription's
     *    `plan_id` is already the requested plan (422).
     *  - Resets `ends_at` to `now()->addMonth()`. `trial_ends_at` is
     *    intentionally untouched so a trialing subscription keeps its
     *    trial clock when the plan changes mid-trial.
     *  - Does NOT mutate any `Entitlement` row. `Purchase` and
     *    `Direct` entitlements persist by design; the read-path
     *    feature gate (`EnsureTenantHasFeature`,
     *    `ResourceController::userCanAccess`) blocks premium-only
     *    features on downgrade without any new code.
     */
    public function applyPlanChange(Subscription $subscription, Plan $newPlan): void
    {
        abort_if(
            $subscription->plan_id === $newPlan->id,
            422,
            'You are already on this plan.',
        );

        $subscription->update([
            'plan_id' => $newPlan->id,
            'ends_at' => now()->addMonth(),
        ]);
    }
}
