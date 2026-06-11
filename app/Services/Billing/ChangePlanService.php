<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionEventType;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Shared mutation that backs the two write surfaces for plan change:
 *   - Tenant-side: Billing\PlanChangeController
 *   - Landlord-side: Landlord\SubscriptionChangeController
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
     *  - Records a subscription history entry after the plan change.
     */
    public function applyPlanChange(Subscription $subscription, Plan $newPlan, ?Request $request = null): void
    {
        abort_if(
            $subscription->plan_id === $newPlan->id,
            422,
            'You are already on this plan.',
        );

        // Snapshot old plan state before mutation
        $oldPlan = $subscription->plan;
        $oldStatus = $subscription->status;

        $subscription->update([
            'plan_id' => $newPlan->id,
            'ends_at' => now()->addMonth(),
        ]);

        // Record history entry
        try {
            SubscriptionHistory::record([
                'subscription_id' => $subscription->id,
                'tenant_id' => $subscription->tenant_id,
                'event_type' => SubscriptionEventType::PlanChanged,
                'actor_id' => $request?->user()?->id,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'old_plan_name' => $oldPlan->name,
                'old_plan_price_cents' => $oldPlan->price_cents,
                'old_plan_features' => $oldPlan->features,
                'old_status' => $oldStatus->value,
                'new_plan_name' => $newPlan->name,
                'new_plan_price_cents' => $newPlan->price_cents,
                'new_plan_features' => $newPlan->features,
                'new_status' => $subscription->status->value,
                'billing_period_start' => now(),
                'billing_period_end' => now()->addMonth(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to record subscription history', ['error' => $e->getMessage()]);
        }
    }
}
