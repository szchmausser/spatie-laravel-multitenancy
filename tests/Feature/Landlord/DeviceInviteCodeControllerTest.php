<?php

use App\Models\DeviceInviteCode;
use App\Models\Landlord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * Helper to create a tenant without provisioning events.
 */
function makeTenant(): Tenant
{
    return Tenant::factory()->createQuietly();
}

beforeEach(function () {
    // Point the tenant connection to the same DB as landlord so tests
    // that create User instances (tenant model) don't fail with
    // "no existe la relación «users»" on an empty tenant connection.
    $testDatabase = config('database.connections.landlord.database');
    config(['database.connections.tenant.database' => $testDatabase]);
    DB::purge('tenant');

    $this->admin = Landlord::factory()->create();
    $this->actingAs($this->admin);
});

it('redirects unauthenticated users to login', function () {
    auth()->logout();

    $this->get(route('landlord.invite-codes.index'))
        ->assertRedirect();
});

it('returns 403 for non-admin tenant users', function () {
    auth()->logout();

    $this->actingAs(User::factory()->createQuietly());

    $this->get(route('landlord.invite-codes.index'))
        ->assertForbidden();
});

it('lists invite codes on the index page', function () {
    $tenant = makeTenant();
    DeviceInviteCode::factory()
        ->forTenant($tenant)
        ->count(3)
        ->create();

    $this->get(route('landlord.invite-codes.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/invite-codes/index')
            ->has('codes', 3)
        );
});

it('shows empty state when no invite codes exist', function () {
    $this->get(route('landlord.invite-codes.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/invite-codes/index')
            ->has('codes', 0)
        );
});

it('shows the create form with tenants list', function () {
    $tenant = makeTenant();

    $this->get(route('landlord.invite-codes.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/invite-codes/create')
            ->has('tenants', 1)
        );
});

it('creates a new invite code with valid data', function () {
    $tenant = makeTenant();

    $this->post(route('landlord.invite-codes.store'), [
        'tenant_id' => $tenant->id,
        'expires_days' => 30,
    ])->assertRedirect(route('landlord.invite-codes.index'));

    $this->assertDatabaseHas('device_invite_codes', [
        'tenant_id' => $tenant->id,
        'created_by' => $this->admin->id,
    ]);
});

it('creates an invite code without expiration when days is 0', function () {
    $tenant = makeTenant();

    $this->post(route('landlord.invite-codes.store'), [
        'tenant_id' => $tenant->id,
        'expires_days' => 0,
    ])->assertRedirect();

    $code = DeviceInviteCode::where('tenant_id', $tenant->id)->first();

    expect($code)->not->toBeNull();
    expect($code->expires_at)->toBeNull();
});

it('fails to create an invite code with invalid tenant', function () {
    $this->post(route('landlord.invite-codes.store'), [
        'tenant_id' => 99999,
        'expires_days' => 30,
    ])->assertSessionHasErrors('tenant_id');
});

it('fails to create an invite code with out-of-range expiration', function () {
    $tenant = makeTenant();

    $this->post(route('landlord.invite-codes.store'), [
        'tenant_id' => $tenant->id,
        'expires_days' => 999,
    ])->assertSessionHasErrors('expires_days');
});

it('shows the edit form with code details', function () {
    $tenant = makeTenant();
    $code = DeviceInviteCode::factory()->forTenant($tenant)->create();

    $this->get(route('landlord.invite-codes.edit', $code))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/invite-codes/edit')
            ->has('code')
        );
});

it('updates the expiration of an invite code', function () {
    $tenant = makeTenant();
    $code = DeviceInviteCode::factory()->forTenant($tenant)
        ->expiresIn(7)
        ->create();

    $originalExpiresAt = $code->expires_at;

    $this->put(route('landlord.invite-codes.update', $code), [
        'expires_days' => 60,
    ])->assertRedirect(route('landlord.invite-codes.index'));

    $code->refresh();

    expect($code->expires_at)->not->toEqual($originalExpiresAt);
});

it('removes expiration when updating to expires_days 0', function () {
    $tenant = makeTenant();
    $code = DeviceInviteCode::factory()->forTenant($tenant)
        ->expiresIn(7)
        ->create();

    $this->put(route('landlord.invite-codes.update', $code), [
        'expires_days' => 0,
    ])->assertRedirect();

    $code->refresh();

    expect($code->expires_at)->toBeNull();
});

it('deletes an invite code', function () {
    $tenant = makeTenant();
    $code = DeviceInviteCode::factory()->forTenant($tenant)->create();

    $this->delete(route('landlord.invite-codes.destroy', $code))
        ->assertRedirect(route('landlord.invite-codes.index'));

    $this->assertDatabaseMissing('device_invite_codes', [
        'id' => $code->id,
    ]);
});
