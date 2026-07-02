<?php

use App\Models\Landlord;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Browser tests for tenant CRUD operations.
 *
 * These tests use Playwright via pest-browser to exercise the actual UI.
 * The Tenant model's `creating` callback provisions real PostgreSQL databases
 * via DDL (CREATE DATABASE), which cannot run inside a transaction. The
 * browser test HTTP server handles each request independently (no wrapping
 * transaction), so DDL operations execute unfettered.
 *
 * Keep the test database clean by running these tests against a disposable
 * CI database, or be prepared to drop leftover tenant databases after
 * running this test suite.
 */
beforeEach(function () {
    $this->admin = Landlord::factory()->createQuietly();

    // Ensure the 'free' plan exists for Tenant::created → ensureDefaultSubscription()
    Plan::factory()->create([
        'slug' => 'free',
        'name' => 'Free',
        'price_cents' => 0,
    ]);
});

test('index page shows tenant list', function () {
    $tenant = Tenant::factory()->createQuietly();

    $this->actingAs($this->admin)
        ->visit(route('landlord.tenants.index'))
        ->assertSee('Tenants')
        ->assertSee($tenant->name)
        ->assertNoJavaScriptErrors();
});

test('create page loads with form fields', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.tenants.create'))
        ->assertSee('Create Tenant')
        ->assertSee('Name')
        ->assertSee('Domain')
        ->assertSee('Database')
        ->assertNoJavaScriptErrors();
});

test('tenant creation flow', function () {
    $tenantName = 'Browser Test Tenant';
    $tenantDomain = 'browser-test.example.com';
    $tenantDatabase = 'browser_test_tenant';

    $this->actingAs($this->admin)
        ->visit(route('landlord.tenants.create'))
        ->type('@input-name', $tenantName)
        ->type('@input-domain', $tenantDomain)
        ->type('@input-database', $tenantDatabase)
        ->click('@submit-tenant-btn')
        ->waitForText($tenantName)
        ->assertNoJavaScriptErrors();
});

test('shows validation errors when required fields are empty', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.tenants.create'))
        ->click('@submit-tenant-btn')
        ->waitForText('required')
        ->assertNoJavaScriptErrors();
});

test('detail page shows tenant information', function () {
    $tenant = Tenant::factory()->createQuietly();

    $this->actingAs($this->admin)
        ->visit(route('landlord.tenants.show', $tenant))
        ->assertSee($tenant->name)
        ->assertSee($tenant->domain)
        ->assertSee($tenant->database)
        ->assertNoJavaScriptErrors();
});

test('edit page loads with tenant data', function () {
    $tenant = Tenant::factory()->createQuietly();

    $this->actingAs($this->admin)
        ->visit(route('landlord.tenants.edit', $tenant))
        ->assertSee('Edit')
        ->assertValue('@edit-input-name', $tenant->name)
        ->assertNoJavaScriptErrors();
});

test('edit flow updates tenant name', function () {
    $tenant = Tenant::factory()->createQuietly();
    $updatedName = 'Updated Browser Name';

    $this->actingAs($this->admin)
        ->visit(route('landlord.tenants.edit', $tenant))
        ->type('@edit-input-name', $updatedName)
        ->click('@edit-tenant-submit-btn')
        ->waitForText($updatedName)
        ->assertNoJavaScriptErrors();
});

test('delete flow removes tenant from list', function () {
    $admin = Landlord::factory()->createQuietly();
    $tenant = Tenant::factory()->createQuietly();

    // The destroy action attempts DROP DATABASE which cannot run inside a
    // transaction. The browser test server handles requests independently
    // (no wrapping transaction), so DDL could execute. However, to avoid
    // dependency on PostgreSQL permissions, mock the statement call.
    DB::partialMock()->shouldReceive('statement')->andReturn(true);

    $this->actingAs($admin)
        ->visit(route('landlord.tenants.show', $tenant))
        ->click('@delete-tenant-trigger')
        ->click('@confirm-delete-btn')
        ->assertDontSee($tenant->name)
        ->assertNoJavaScriptErrors();
});

test('unauthenticated access redirects to login', function () {
    $this->visit(route('landlord.tenants.index'))
        ->assertPathIs('/login')
        ->assertSee('Log in');
});
