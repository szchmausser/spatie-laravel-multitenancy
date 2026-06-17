<?php

use App\Models\Resource;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Browser\Concerns\TenantConnectionHelpers;

uses(TenantConnectionHelpers::class);

/**
 * Browser tests for the BuyResourceDialog interaction.
 *
 * Covers:
 *   - Click "Buy" on catalog card → dialog opens with resource details
 *   - Dialog shows resource name, price, file metadata
 *   - Click Cancel → dialog closes
 *   - Click "Proceed to Payment" → creates order → redirects to billing
 *
 * The buy flow is the Phase 1.5F placeholder for real payment.
 * If this breaks, users cannot purchase premium resources.
 *
 * NOTE: The dialog title uses HTML smart quotes (&ldquo; / &rdquo;)
 * which render as \u201C and \u201D. Tests avoid matching the exact
 * quoted title string and instead use data-testid selectors.
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

test('clicking buy button opens dialog with resource details', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    Resource::factory()->create([
        'name' => 'Premium Ebook',
        'slug' => 'premium-ebook',
        'description' => 'A comprehensive guide.',
        'is_premium' => true,
        'price_cents' => 3500,
        'file_size_bytes' => 2048000,
        'mime_type' => 'application/pdf',
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Buyer User',
            'email' => 'buyer-user@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('member');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit('/resources')
            ->waitForText('Premium Ebook')
            ->click('[data-testid="resource-buy-btn-premium-ebook"]')
            ->waitForText('Review the resource details and proceed to payment.')
            ->assertVisible('[data-testid="buy-dialog-premium-ebook"]')
            ->assertVisible('[data-testid="buy-dialog-title-premium-ebook"]')
            ->assertVisible('[data-testid="buy-dialog-size-premium-ebook"]')
            ->assertVisible('[data-testid="buy-dialog-mime-premium-ebook"]')
            ->assertVisible('[data-testid="buy-dialog-price-premium-ebook"]')
            ->assertVisible('[data-testid="buy-confirm-btn-premium-ebook"]')
            ->assertVisible('[data-testid="buy-cancel-btn-premium-ebook"]')
            ->assertSee('Proceed to Payment')
            ->assertSee('Cancel')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('clicking cancel closes the buy dialog', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    Resource::factory()->create([
        'name' => 'Cancel Test Resource',
        'slug' => 'cancel-test-resource',
        'is_premium' => true,
        'price_cents' => 1500,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Cancel User',
            'email' => 'cancel-user@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('member');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit('/resources')
            ->waitForText('Cancel Test Resource')
            ->click('[data-testid="resource-buy-btn-cancel-test-resource"]')
            ->waitForText('Review the resource details and proceed to payment.')
            ->assertVisible('[data-testid="buy-dialog-cancel-test-resource"]')
            ->click('[data-testid="buy-cancel-btn-cancel-test-resource"]')
            ->assertMissing('[data-testid="buy-dialog-cancel-test-resource"]')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('proceed to payment creates order and redirects to billing', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    Resource::factory()->create([
        'name' => 'Payment Test Ebook',
        'slug' => 'payment-test-ebook',
        'is_premium' => true,
        'price_cents' => 5000,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Payment User',
            'email' => 'payment-user@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('member');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit('/resources')
            ->waitForText('Payment Test Ebook')
            ->click('[data-testid="resource-buy-btn-payment-test-ebook"]')
            ->waitForText('Review the resource details and proceed to payment.')
            ->click('[data-testid="buy-confirm-btn-payment-test-ebook"]')
            ->waitForText('Orden')
            ->assertSee('Orden')
            ->assertPathContains('/billing/orders/')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});

test('buy dialog shows correct price for premium resource', function () {
    $testDatabase = config('database.connections.landlord.database');
    $tenant = Tenant::factory()->createQuietly([
        'domain' => '127.0.0.1',
        'database' => $testDatabase,
    ]);

    Resource::factory()->create([
        'name' => 'Price Check Resource',
        'slug' => 'price-check-resource',
        'is_premium' => true,
        'price_cents' => 9999,
    ]);

    $previousDefault = $this->setupTenantConnectionForTest();

    try {
        $user = User::on('tenant')->create([
            'name' => 'Price Check User',
            'email' => 'price-check-user@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $user->assignRole('member');

        $this->fakeTenantFinderForTest($tenant);
        $tenant->makeCurrent();

        $this->actingAs($user)
            ->visit('/resources')
            ->waitForText('Price Check Resource')
            ->click('[data-testid="resource-buy-btn-price-check-resource"]')
            ->waitForText('Review the resource details and proceed to payment.')
            ->assertVisible('[data-testid="buy-dialog-price-price-check-resource"]')
            ->assertSeeIn('[data-testid="buy-dialog-price-price-check-resource"]', '$99.99')
            ->assertNoJavaScriptErrors();
    } finally {
        $this->cleanupTenantConnection($previousDefault);
    }
});
