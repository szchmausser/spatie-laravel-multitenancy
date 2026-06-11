<?php

namespace App\Http\Controllers\Landlord;

use App\Enums\ActorType;
use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Landlord;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
            'reason' => 'nullable|string|max:500',
        ]);

        // Check if subscription already exists to determine event type
        $existing = Subscription::on('landlord')->where('tenant_id', $tenant->id)->first();

        $subscription = Subscription::on('landlord')->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'plan_id' => $validated['plan_id'],
                'status' => SubscriptionStatus::Active,
                'ends_at' => null,
            ]
        );

        // Record in history: Created if new, PlanChanged if existing
        try {
            $plan = $subscription->plan;
            $isCreate = $existing === null;
            $actor = $request->user();

            SubscriptionHistory::record([
                'subscription_id' => $subscription->id,
                'tenant_id' => $tenant->id,
                'event_type' => $isCreate
                    ? SubscriptionEventType::SubscriptionCreated
                    : SubscriptionEventType::PlanChanged,
                'actor_id' => $actor instanceof Landlord ? $actor->getKey() : null,
                'actor_name' => $actor?->name,
                'actor_email' => $actor?->email,
                'actor_type' => $actor instanceof Landlord
                    ? ActorType::Landlord
                    : ($actor ? ActorType::Tenant : ActorType::System),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'reason' => $request->input('reason'),
                'old_plan_name' => $isCreate ? null : $existing->plan?->name,
                'old_plan_price_cents' => $isCreate ? null : $existing->plan?->price_cents,
                'old_plan_features' => $isCreate ? null : $existing->plan?->features,
                'old_status' => $isCreate ? null : $existing->status->value,
                'new_plan_name' => $plan?->name,
                'new_plan_price_cents' => $plan?->price_cents,
                'new_plan_features' => $plan?->features,
                'new_status' => $subscription->status->value,
                'amount_cents' => $plan?->price_cents,
                'currency' => 'USD',
                'billing_period_start' => now(),
                'billing_period_end' => now()->addMonth(),
                'correlation_id' => Str::uuid(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to record subscription history', ['error' => $e->getMessage()]);
        }

        return redirect()->route('landlord.tenants.show', $tenant);
    }
}
