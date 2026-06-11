<?php

namespace App\Http\Controllers\Landlord;

use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class SubscriptionController extends Controller
{
    /**
     * Display a listing of all subscriptions.
     */
    public function index()
    {
        $subscriptions = Subscription::with(['tenant', 'plan'])->get();

        return Inertia::render('landlord/subscriptions/index', [
            'subscriptions' => $subscriptions,
        ]);
    }

    /**
     * Display the specified subscription.
     */
    public function show(Subscription $subscription)
    {
        $subscription->load(['tenant', 'plan']);

        return Inertia::render('landlord/subscriptions/show', [
            'subscription' => $subscription,
        ]);
    }

    /**
     * Assign a plan to a tenant.
     *
     * Creates a new subscription or updates an existing one.
     * The UNIQUE(tenant_id) constraint ensures one subscription per tenant.
     */
    public function assign(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
        ]);

        $subscription = Subscription::on('landlord')->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'plan_id' => $validated['plan_id'],
                'status' => SubscriptionStatus::Active,
                'ends_at' => null,
            ]
        );

        // Record subscription creation in history
        try {
            $plan = $subscription->plan;
            SubscriptionHistory::record([
                'subscription_id' => $subscription->id,
                'tenant_id' => $tenant->id,
                'event_type' => SubscriptionEventType::SubscriptionCreated,
                'actor_id' => $request->user()?->getKey(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'new_plan_name' => $plan?->name,
                'new_plan_price_cents' => $plan?->price_cents,
                'new_plan_features' => $plan?->features,
                'new_status' => $subscription->status->value,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to record subscription history', ['error' => $e->getMessage()]);
        }

        return redirect()->route('landlord.tenants.show', $tenant);
    }
}
