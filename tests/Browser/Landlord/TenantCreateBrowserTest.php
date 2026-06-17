<?php

use App\Models\Landlord;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * Browser test for creating a new tenant from the landlord admin panel.
 *
 * Covers:
 *   - Create page loads with form fields
 *   - Creating a new tenant fills the form and submits
 *   - Validation errors when required fields are empty
 *
 * The 'free' plan must exist because Tenant::created() calls
 * ensureDefaultSubscription() which looks up the plan by slug.
 *
 * IMPORTANT: The create form triggers Tenant::create() which physically
 * creates a PostgreSQL database. This database MUST be dropped in
 * afterEach to keep tests self-sufficient (SKILL.md Principle 2).
 */
beforeEach(function () {
    $this->admin = Landlord::factory()->createQuietly();

    // Seed the 'free' plan — required by Tenant::created() callback
    Plan::query()->updateOrCreate(
        ['slug' => 'free'],
        [
            'name' => 'Free',
            'description' => 'Free plan',
            'features' => ['premium-zone' => false],
            'price_cents' => 0,
            'is_active' => true,
        ],
    );

    // Track databases created during the test so we can clean them up
    $this->createdDatabases = [];
});

afterEach(function () {
    // Drop any physical databases created by Tenant::create() during the test
    foreach ($this->createdDatabases as $dbName) {
        DB::connection('landlord')->unprepared(
            'DROP DATABASE IF EXISTS "'.$dbName.'"'
        );
    }
});

test('create tenant page loads with form fields', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.tenants.create'))
        ->waitForText('Create Tenant')
        ->assertSee('Create Tenant')
        ->assertSee('Name')
        ->assertSee('Domain')
        ->assertSee('Database')
        ->assertVisible('input[name="name"]')
        ->assertVisible('input[name="domain"]')
        ->assertVisible('input[name="database"]')
        ->assertVisible('[data-testid="submit-tenant-btn"]')
        ->assertNoJavaScriptErrors();
});

test('creating a new tenant fills form and submits', function () {
    $tenantName = 'Test Tenant';
    $tenantDomain = 'test-tenant.example.com';
    $tenantDatabase = 'test_tenant_db';

    // Track this database for cleanup in afterEach
    $this->createdDatabases[] = $tenantDatabase;

    $this->actingAs($this->admin)
        ->visit(route('landlord.tenants.create'))
        ->waitForText('Create Tenant')
        ->type('input[name="name"]', $tenantName)
        ->type('input[name="domain"]', $tenantDomain)
        ->type('input[name="database"]', $tenantDatabase)
        ->click('[data-testid="submit-tenant-btn"]')
        // After successful creation, user is redirected to tenant index
        ->waitForText('Tenants')
        ->assertSee($tenantName)
        ->assertNoJavaScriptErrors();
});

test('create tenant shows validation errors when fields are empty', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.tenants.create'))
        ->waitForText('Create Tenant')
        ->click('[data-testid="submit-tenant-btn"]')
        ->waitForText('required')
        ->assertSee('required')
        ->assertNoJavaScriptErrors();
});
