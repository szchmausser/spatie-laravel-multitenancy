<?php

use App\Models\Landlord;
use App\Models\Plan;
use App\Models\Tenant;

/**
 * Browser tests for the plans + subscription assignment flow.
 *
 * Exercises the UI created in PR #1B to make sure the landlord can:
 *  - browse the plans list with data
 *  - create a new plan
 *  - assign that plan to a tenant from the tenant detail page
 *  - see the active plan reflected back on the tenant detail page
 *
 * The tenant is created with `createQuietly()` to skip the `creating` callback
 * (which would issue a real CREATE DATABASE). The plan / subscription live in
 * the landlord connection, so they migrate normally with RefreshDatabase.
 */
beforeEach(function () {
    $this->admin = Landlord::factory()->createQuietly();
});

test('plans list renders and shows plans', function () {
    Plan::factory()->create(['name' => 'Premium Plan', 'slug' => 'premium', 'is_active' => true]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.plans.index'))
        ->assertSee('Plans')
        ->assertSee('Premium Plan')
        ->assertNoJavaScriptErrors();
});

test('admin can create a plan with features', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.plans.create'))
        ->assertSee('Create Plan')
        ->type('@input-name', 'Pro')
        ->type('@input-slug', 'pro')
        ->type('@input-description', 'Pro tier with premium access')
        ->check('@input-feature-premium-zone')
        ->click('@submit-plan-btn')
        ->waitForText('Pro')
        ->assertNoJavaScriptErrors();

    expect(Plan::query()->where('slug', 'pro')->exists())->toBeTrue();
});

test('admin can assign a plan to a tenant from the tenant detail page', function () {
    $plan = Plan::factory()->create([
        'name' => 'Assigned Plan',
        'slug' => 'assigned-plan',
        'is_active' => true,
        'features' => ['premium-zone' => true],
    ]);

    $tenant = Tenant::factory()->createQuietly();

    $this->actingAs($this->admin)
        ->visit(route('landlord.tenants.show', $tenant))
        ->assertSee('No plan assigned')
        ->select('@plan-select', (string) $plan->id)
        ->click('@assign-plan-btn')
        ->waitForText('Assigned Plan')
        ->assertNoJavaScriptErrors();

    $tenant->refresh();
    expect($tenant->subscription)->not->toBeNull();
    expect($tenant->subscription->plan_id)->toBe($plan->id);
    expect($tenant->subscription->status->value)->toBe('active');
});
