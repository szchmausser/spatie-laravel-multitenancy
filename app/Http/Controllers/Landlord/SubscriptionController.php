<?php

namespace App\Http\Controllers\Landlord;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Http\Request;
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

        return redirect()->route('landlord.tenants.show', $tenant);
    }
}
