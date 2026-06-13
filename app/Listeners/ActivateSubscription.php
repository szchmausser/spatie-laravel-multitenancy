<?php

namespace App\Listeners;

use App\Enums\ActorType;
use App\Enums\EntitlementGrantVia;
use App\Enums\OrderStatus;
use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Events\PaymentVerified;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ActivateSubscription
{
    /**
     * Handle the PaymentVerified event.
     *
     * If the order is fully paid AND has a plan_id, create or update
     * the tenant's subscription. Idempotent — checks for existing
     * active subscription before creating.
     */
    public function handle(PaymentVerified $event): void
    {
        $payment = $event->payment;
        $order = $payment->order;

        // Reload to get accurate paid_cents via accessor
        $order->refresh();

        if (! $order->isFullyPaid()) {
            return;
        }

        // Idempotency guard: if order is already Paid, the subscription
        // was already activated — prevent duplicate history records.
        if ($order->status === OrderStatus::Paid) {
            return;
        }

        $order->update(['status' => OrderStatus::Paid]);

        // For resource orders: grant entitlement to all tenant users
        if ($order->resource_id !== null) {
            $this->grantResourceEntitlement($order);

            return;
        }

        // Only activate subscription for plan orders
        if ($order->plan_id === null) {
            return;
        }

        $newPlan = Plan::find($order->plan_id);

        // Idempotent: find existing subscription or create new one
        $subscription = Subscription::on('landlord')
            ->where('tenant_id', $order->tenant_id)
            ->first();

        $oldPlan = $subscription?->plan;
        $oldStatus = $subscription?->status;

        if ($subscription) {
            $subscription->update([
                'plan_id' => $order->plan_id,
                'status' => SubscriptionStatus::Active,
                'ends_at' => now()->addMonth(),
            ]);
        } else {
            $subscription = Subscription::on('landlord')->create([
                'tenant_id' => $order->tenant_id,
                'plan_id' => $order->plan_id,
                'status' => SubscriptionStatus::Active,
                'ends_at' => now()->addMonth(),
            ]);
        }

        // Record history entry
        try {
            SubscriptionHistory::record([
                'subscription_id' => $subscription->id,
                'tenant_id' => $order->tenant_id,
                'event_type' => SubscriptionEventType::PlanChanged,
                'actor_id' => null,
                'actor_name' => 'System',
                'actor_email' => null,
                'actor_type' => ActorType::System,
                'ip_address' => null,
                'user_agent' => null,
                'reason' => 'Payment verified for Order #'.$order->id,
                'old_plan_name' => $oldPlan?->name,
                'old_plan_price_cents' => $oldPlan?->price_cents,
                'old_plan_features' => $oldPlan?->features,
                'old_status' => $oldStatus?->value,
                'new_plan_name' => $newPlan?->name,
                'new_plan_price_cents' => $newPlan?->price_cents,
                'new_plan_features' => $newPlan?->features,
                'new_status' => $subscription->status->value,
                'amount_cents' => $order->total_cents,
                'currency' => 'USD',
                'billing_period_start' => now(),
                'billing_period_end' => now()->addMonth(),
                'correlation_id' => Str::uuid(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to record subscription history', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Grant entitlement to all users of the tenant for the purchased resource.
     *
     * Resource orders are tenant-level purchases — when a tenant buys a
     * resource, all its users should have access. The user_id on the
     * entitlement is a logical FK to the tenant's User table.
     */
    private function grantResourceEntitlement(Order $order): void
    {
        $tenant = Tenant::on('landlord')->find($order->tenant_id);

        if (! $tenant) {
            Log::warning('Tenant not found for resource entitlement', ['tenant_id' => $order->tenant_id]);

            return;
        }

        $tenant->makeCurrent();

        $users = User::query()->get();

        foreach ($users as $user) {
            Entitlement::updateOrCreate(
                [
                    'tenant_id' => $order->tenant_id,
                    'user_id' => $user->getKey(),
                    'resource_id' => $order->resource_id,
                ],
                [
                    'granted_via' => EntitlementGrantVia::Purchase,
                    'granted_at' => now(),
                    'expires_at' => null,
                ],
            );
        }
    }
}
