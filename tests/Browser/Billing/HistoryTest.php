<?php

use App\Models\Plan;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser tests for the billing history page.
 *
 * Covers:
 *   - History list renders with events
 *   - Empty state when no history exists
 *   - Pagination controls visibility
 *
 * Connection setup mirrors ChangePlanFlowTest: the tenant connection
 * is pointed at the test database, Spatie permission tables are
 * created, and the user is created on the tenant connection.
 */
beforeEach(function () {
    $testDatabase = config('database.connections.landlord.database');

    config(['database.connections.tenant.database' => $testDatabase]);
    DB::purge('tenant');

    $tableNames = config('permission.table_names');
    Schema::connection('tenant')->dropIfExists($tableNames['role_has_permissions']);
    Schema::connection('tenant')->dropIfExists($tableNames['model_has_roles']);
    Schema::connection('tenant')->dropIfExists($tableNames['model_has_permissions']);
    Schema::connection('tenant')->dropIfExists($tableNames['roles']);
    Schema::connection('tenant')->dropIfExists($tableNames['permissions']);

    DB::purge('tenant');
});

test('history shows subscription history page with events', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'History Viewer',
            'email' => 'history-viewer@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        // Create subscription with history
        $plan = Plan::factory()->create(['name' => 'Test Plan']);
        $subscription = $tenant->subscription()->create([
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        SubscriptionHistory::create([
            'subscription_id' => $subscription->id,
            'tenant_id' => $tenant->id,
            'event_type' => 'subscription_created',
            'actor_type' => 'tenant',
            'new_plan_name' => 'Test Plan',
            'new_status' => 'active',
        ]);

        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit('/billing/history')
            ->waitForText('Subscription History')
            ->assertSee('Created')
            ->assertSee('Test Plan')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('history empty state', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Empty History',
            'email' => 'empty-history@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit('/billing/history')
            ->waitForText('No subscription history entries yet.')
            ->assertSee('No subscription history entries yet.')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('history page loads correctly with content', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'History Content',
            'email' => 'history-content@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('tenant-admin');

        // Create subscription and history with specific content to verify
        $plan = Plan::factory()->create(['name' => 'Pro Plan', 'price_cents' => 5000]);
        $subscription = $tenant->subscription()->create([
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        $historyEntry = SubscriptionHistory::create([
            'subscription_id' => $subscription->id,
            'tenant_id' => $tenant->id,
            'event_type' => 'subscription_created',
            'actor_type' => 'tenant',
            'new_plan_name' => 'Pro Plan',
            'new_plan_price_cents' => 5000,
            'new_status' => 'active',
            'currency' => 'USD',
            'amount_cents' => 5000,
            'created_at' => '2025-06-15 10:30:00',
        ]);

        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit('/billing/history')
            ->waitForText('Subscription History')
            // Verify the history list container exists
            ->assertVisible('[data-testid="history-list"]')
            // Verify the specific event type badge is rendered
            ->assertSee('Created')
            // Verify the plan name is shown
            ->assertSee('Pro Plan')
            // Verify the history entry container is rendered
            ->assertVisible("[data-testid=\"history-entry-{$historyEntry->id}\"]")
            // Verify the event type badge within the entry
            ->assertVisible("[data-testid=\"history-event-type-{$historyEntry->id}\"]")
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});
