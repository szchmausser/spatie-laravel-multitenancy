<?php

use App\Enums\SubscriptionStatus;
use App\Models\Landlord;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;

/**
 * Browser tests for the tenants index page.
 *
 * The index page shows each tenant together with the most important
 * fields of its current subscription (plan name, status, ends_at) so
 * the landlord can spot problems at a glance without having to
 * open each detail page.
 */
beforeEach(function () {
    $this->admin = Landlord::factory()->createQuietly();
});

test('tenants index page lists every tenant with its plan and status', function () {
    $basicPlan = Plan::factory()->create([
        'name' => 'Basic Plan',
        'slug' => 'basic',
        'is_active' => true,
    ]);

    $tenant1 = Tenant::factory()->createQuietly();
    Subscription::on('landlord')->create([
        'tenant_id' => $tenant1->id,
        'plan_id' => $basicPlan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => '2027-01-15 00:00:00',
    ]);

    $tenant2 = Tenant::factory()->createQuietly();
    Subscription::on('landlord')->create([
        'tenant_id' => $tenant2->id,
        'plan_id' => $basicPlan->id,
        'status' => SubscriptionStatus::Cancelled,
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.tenants.index'))
        ->assertSee('Tenants')
        // Plan name shows up next to every tenant
        ->assertSee('Basic Plan')
        // Status badges are rendered for both subscriptions
        ->assertSeeIn("@tenant-sub-status-{$tenant1->id}", 'active')
        ->assertSeeIn("@tenant-sub-status-{$tenant2->id}", 'cancelled')
        // Expiration date is human-readable (Jan 15, 2027) for tenant1,
        // and the "No expiry" fallback is shown for tenant2
        ->assertSeeIn("@tenant-sub-ends-{$tenant1->id}", 'Jan 15, 2027')
        ->assertSeeIn("@tenant-sub-ends-{$tenant2->id}", 'Sin expiración')
        ->assertNoJavaScriptErrors();
});
