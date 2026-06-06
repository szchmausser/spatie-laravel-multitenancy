<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

/**
 * Shared mutation that backs the two write surfaces for plan change:
 *   - Tenant-side: Billing\ChangePlanController
 *   - Landlord-side: Landlord\ChangePlanController
 *
 * The row lock, the same-plan guard, and the `ends_at` reset live
 * HERE. Controllers are thin auth + resolution wrappers; both call
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
     * Apply a plan change inside a row-locked transaction.
     *
     *  - Acquires a pessimistic `FOR UPDATE` lock on the subscription
     *    row for the duration of the transaction. A second concurrent
     *    POST that hits the same row blocks at `lockForUpdate()` until
     *    the first commits, then re-reads the updated `plan_id` and
     *    trips the same-plan guard.
     *  - Refuses to apply the change when the locked row's `plan_id`
     *    is already the requested plan (idempotent 422 — the UI uses
     *    this to mean "no-op" instead of silently re-charging).
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
        DB::transaction(function () use ($subscription, $newPlan): void {
            $locked = Subscription::query()
                ->whereKey($subscription->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            abort_if(
                $locked->plan_id === $newPlan->id,
                422,
                'You are already on this plan.',
            );

            $locked->update([
                'plan_id' => $newPlan->id,
                'ends_at' => now()->addMonth(),
            ]);
        });
    }
}
