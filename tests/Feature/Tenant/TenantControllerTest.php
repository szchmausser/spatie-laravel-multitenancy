<?php

use App\Models\Landlord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Disable CSRF for HTTP POST/PUT/DELETE in feature tests
    $this->withoutMiddleware(VerifyCsrfToken::class);
});

test('index returns ok', function () {
    $admin = Landlord::factory()->createQuietly();
    $tenants = Tenant::factory()->count(2)->createQuietly();

    $this->actingAs($admin)
        ->get(route('landlord.tenants.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('landlord/tenants/index')
            ->has('tenants', 2)
        );
});

test('create returns ok', function () {
    $admin = Landlord::factory()->createQuietly();

    $this->actingAs($admin)
        ->get(route('landlord.tenants.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('landlord/tenants/create')
        );
});

test('store creates a tenant', function () {
    $admin = Landlord::factory()->createQuietly();

    // Disable Tenant events to prevent real DB creation (CREATE DATABASE inside transaction)
    $dispatcher = Tenant::getEventDispatcher();
    Tenant::unsetEventDispatcher();

    try {
        $this->actingAs($admin)
            ->post(route('landlord.tenants.store'), [
                'name' => 'New Tenant',
                'domain' => 'newtenant.test',
                'database' => 'new_tenant_db',
            ])
            ->assertRedirect(route('landlord.tenants.index'));
    } finally {
        Tenant::setEventDispatcher($dispatcher);
    }

    $this->assertDatabaseHas('tenants', [
        'name' => 'New Tenant',
        'domain' => 'newtenant.test',
        'database' => 'new_tenant_db',
    ], 'landlord');
});

test('store validates required fields', function () {
    $admin = Landlord::factory()->createQuietly();

    $this->actingAs($admin)
        ->post(route('landlord.tenants.store'), [])
        ->assertSessionHasErrors(['name', 'domain', 'database']);
});

test('show returns ok', function () {
    $admin = Landlord::factory()->createQuietly();
    $tenant = Tenant::factory()->createQuietly();

    $this->actingAs($admin)
        ->get(route('landlord.tenants.show', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('landlord/tenants/show')
            ->has('tenant')
        );
});

test('edit returns ok', function () {
    $admin = Landlord::factory()->createQuietly();
    $tenant = Tenant::factory()->createQuietly();

    $this->actingAs($admin)
        ->get(route('landlord.tenants.edit', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('landlord/tenants/edit')
            ->has('tenant')
        );
});

test('update modifies a tenant', function () {
    $admin = Landlord::factory()->createQuietly();
    $tenant = Tenant::factory()->createQuietly();

    $this->actingAs($admin)
        ->put(route('landlord.tenants.update', $tenant), [
            'name' => 'Updated Name',
            'domain' => $tenant->domain,
            'database' => $tenant->database,
        ])
        ->assertRedirect(route('landlord.tenants.index'));

    $this->assertDatabaseHas('tenants', [
        'id' => $tenant->id,
        'name' => 'Updated Name',
    ], 'landlord');
});

test('destroy processes deletion for admin', function () {
    $admin = Landlord::factory()->createQuietly();
    $tenant = Tenant::factory()->createQuietly();

    // DROP DATABASE cannot run inside a transaction, so mock it.
    DB::partialMock()->shouldReceive('statement')->andReturn(true);

    $this->actingAs($admin)
        ->delete(route('landlord.tenants.destroy', $tenant))
        ->assertRedirect(route('landlord.tenants.index'));
});

test('unauthenticated user is redirected to login', function () {
    $this->get(route('landlord.tenants.index'))
        ->assertRedirect(route('login'));
});

test('non admin landlord user receives forbidden', function () {
    // Create a regular User (tenant user), NOT a Landlord
    $user = User::factory()->createQuietly();

    // User uses UsesTenantConnection, so it inserts into the default connection's users table
    // The middleware checks instance of Landlord, so regular User should get 403
    $this->actingAs($user)
        ->get(route('landlord.tenants.index'))
        ->assertForbidden();
});
