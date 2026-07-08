<?php

use App\Models\Resource;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser tests for the tenant resource catalog → detail navigation flow.
 *
 * Covers the complete user journey:
 *   - Catalog page loads with resource cards (free + premium)
 *   - Click resource name link → navigates to detail page
 *   - Detail page shows correct metadata (name, description, size, type)
 *   - "Back to catalog" button returns to the catalog
 *   - Empty state when no resources exist
 *   - Count badge updates correctly
 *
 * This is the PRIMARY user flow for resource consumption.
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

test('catalog page loads with free and premium resource cards', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $freeResource = Resource::factory()->create([
        'name' => 'Getting Started Guide',
        'slug' => 'getting-started-guide',
        'is_premium' => false,
    ]);

    $premiumResource = Resource::factory()->create([
        'name' => 'Advanced Analytics Report',
        'slug' => 'advanced-analytics-report',
        'is_premium' => true,
        'price_cents' => 2500,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Catalog User',
            'email' => 'catalog-user@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('member');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit('/resources')
            ->waitForText('Resources')
            ->assertSee('Resources')
            ->assertSee('Getting Started Guide')
            ->assertSee('Advanced Analytics Report')
            ->assertVisible('[data-testid="resources-count-badge"]')
            ->assertSeeIn('[data-testid="resources-count-badge"]', '2 resources')
            ->assertVisible('[data-testid="resource-free-badge-getting-started-guide"]')
            ->assertVisible('[data-testid="resource-buy-separate-badge-advanced-analytics-report"]')
            ->assertVisible('[data-testid="resource-card-getting-started-guide"]')
            ->assertVisible('[data-testid="resource-card-advanced-analytics-report"]')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('user clicks resource name to navigate from catalog to detail page', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $resource = Resource::factory()->create([
        'name' => 'User Manual',
        'slug' => 'user-manual',
        'description' => 'Complete user manual for the platform.',
        'is_premium' => false,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Nav User',
            'email' => 'nav-user@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('member');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit('/resources')
            ->waitForText('User Manual')
            ->click('[data-testid="resource-name-link-user-manual"]')
            ->waitForText('User Manual')
            ->assertSee('User Manual')
            ->assertSee('Complete user manual for the platform.')
            ->assertVisible('[data-testid="resource-show-user-manual"]')
            ->assertVisible('[data-testid="resource-show-card-user-manual"]')
            ->assertVisible('[data-testid="resource-show-download-btn-user-manual"]')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('detail page shows correct metadata and back button', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $resource = Resource::factory()->create([
        'name' => 'Data Export Template',
        'slug' => 'data-export-template',
        'description' => 'CSV template for data exports.',
        'file_size_bytes' => 102400,
        'mime_type' => 'text/csv',
        'is_premium' => false,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Detail User',
            'email' => 'detail-user@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('member');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit("/resources/{$resource->slug}")
            ->waitForText('Data Export Template')
            ->assertSee('Data Export Template')
            ->assertSee('CSV template for data exports.')
            ->assertVisible('[data-testid="resource-show-free-badge-data-export-template"]')
            ->assertVisible('[data-testid="resource-show-size-data-export-template"]')
            ->assertVisible('[data-testid="resource-show-mime-data-export-template"]')
            ->assertVisible('[data-testid="back-to-catalog-btn"]')
            ->assertSee('Back to catalog')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('back to catalog button navigates from detail back to catalog', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $resource = Resource::factory()->create([
        'name' => 'Return Test',
        'slug' => 'return-test',
        'is_premium' => false,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Return User',
            'email' => 'return-user@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('member');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit("/resources/{$resource->slug}")
            ->waitForText('Return Test')
            ->click('[data-testid="back-to-catalog-btn"]')
            ->waitForText('Resources')
            ->assertSee('Resources')
            ->assertSee('Return Test')
            ->assertVisible('[data-testid="resources-grid"]')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('empty state shows when no resources exist', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Empty User',
            'email' => 'empty-user@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('member');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit('/resources')
            ->waitForText('Resources')
            ->assertVisible('[data-testid="resources-empty-state"]')
            ->assertSee('No resources available')
            ->assertSee('0 resources')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('premium resource shows price on catalog card', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    Resource::factory()->create([
        'name' => 'Premium Template',
        'slug' => 'premium-template',
        'is_premium' => true,
        'price_cents' => 4999,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Price User',
            'email' => 'price-user@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('member');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit('/resources')
            ->waitForText('Premium Template')
            ->assertVisible('[data-testid="resource-price-premium-template"]')
            ->assertVisible('[data-testid="resource-buy-btn-premium-template"]')
            ->assertSeeIn('[data-testid="resource-buy-btn-premium-template"]', 'Buy')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});
