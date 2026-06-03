<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

test('factory creates a valid tenant', function () {
    $tenant = Tenant::factory()->createQuietly();

    expect($tenant->name)->not->toBeEmpty();
    expect($tenant->domain)->not->toBeEmpty();
    expect($tenant->database)->not->toBeEmpty();

    $this->assertDatabaseHas('tenants', [
        'id' => $tenant->id,
        'name' => $tenant->name,
        'domain' => $tenant->domain,
        'database' => $tenant->database,
    ], 'landlord');
});

test('factory state override pins the database field', function () {
    $tenant = Tenant::factory()->createQuietly(['database' => 'custom_db']);

    expect($tenant->database)->toBe('custom_db');
});

test('tenant has required fillable attributes', function () {
    $tenant = Tenant::withoutEvents(fn () => Tenant::create([
        'name' => 'Test Tenant',
        'domain' => 'test.example.com',
        'database' => 'test_tenant_db',
    ]));

    expect($tenant->name)->toBe('Test Tenant');
    expect($tenant->domain)->toBe('test.example.com');
    expect($tenant->database)->toBe('test_tenant_db');

    $this->assertDatabaseHas('tenants', [
        'id' => $tenant->id,
        'name' => 'Test Tenant',
        'domain' => 'test.example.com',
        'database' => 'test_tenant_db',
    ], 'landlord');
});

test('tenants table guard passes silently when table exists', function () {
    $tenant = Tenant::factory()->make();

    $reflection = new ReflectionMethod($tenant, 'assertTenantsTableExists');

    // When the table exists, the guard should not throw
    $reflection->invoke($tenant);

    expect(true)->toBeTrue();
});

test('tenants table guard throws actionable message on missing table', function () {
    // Use a separate connection to test the guard without affecting the main test
    $tenant = Tenant::factory()->make();
    $reflection = new ReflectionMethod($tenant, 'assertTenantsTableExists');

    // Temporarily swap the landlord connection to point to a DB without the tenants table
    $originalDb = config('database.connections.landlord.database');
    config(['database.connections.landlord.database' => 'postgres']);
    DB::purge('landlord');

    try {
        $reflection->invoke($tenant);
        $this->fail('Expected RuntimeException was not thrown.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('php artisan migrate');
    } finally {
        // Restore the original connection for subsequent tests
        config(['database.connections.landlord.database' => $originalDb]);
        DB::purge('landlord');
        // Reconnect using the restored config
        DB::connection('landlord')->getPdo();
    }
});
