<?php

use App\Models\Device;
use App\Models\DeviceInviteCode;
use App\Models\Tenant;

/**
 * Helper to create a tenant (without provisioning events) and
 * a valid invite code.
 */
function makeInviteCode(?Tenant $tenant = null): DeviceInviteCode
{
    $tenant ??= Tenant::factory()->createQuietly();

    return DeviceInviteCode::factory()->create();
}

it('rejects registration when no invite_code is provided', function () {
    $response = $this->postJson('/api/device/register', [
        'name' => 'Test Device',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('invite_code');
});

it('rejects registration with a non-existent invite code', function () {
    $response = $this->postJson('/api/device/register', [
        'name' => 'Test Device',
        'invite_code' => 'INV-NONEXISTENT',
    ]);

    $response->assertUnauthorized()->assertJson([
        'message' => 'Invalid invite code.',
    ]);
});

it('rejects registration with an already used invite code', function () {
    $tenant = Tenant::factory()->createQuietly();
    $inviteCode = DeviceInviteCode::factory()->used()->create();

    $response = $this->postJson('/api/device/register', [
        'name' => 'Test Device',
        'invite_code' => $inviteCode->code,
    ]);

    $response->assertUnauthorized()->assertJson([
        'message' => 'This invite code has already been used.',
    ]);
});

it('rejects registration with an expired invite code', function () {
    $tenant = Tenant::factory()->createQuietly();
    $inviteCode = DeviceInviteCode::factory()->expired()->create();

    $response = $this->postJson('/api/device/register', [
        'name' => 'Test Device',
        'invite_code' => $inviteCode->code,
    ]);

    $response->assertUnauthorized()->assertJson([
        'message' => 'This invite code has expired.',
    ]);
});

it('creates a new active device when no android_device_id is provided', function () {
    $tenant = Tenant::factory()->createQuietly();
    $inviteCode = makeInviteCode($tenant);

    $response = $this->postJson('/api/device/register', [
        'name' => 'Test Device',
        'invite_code' => $inviteCode->code,
    ]);

    $response->assertCreated()->assertJsonStructure([
        'device_id',
        'token',
        'name',
        'is_active',
    ]);

    $response->assertJsonMissing(['tenant_id']);

    $this->assertDatabaseHas('devices', [
        'name' => 'Test Device',
        'is_active' => true,
    ]);
});

it('creates a new active device when android_device_id is novel', function () {
    $tenant = Tenant::factory()->createQuietly();
    $inviteCode = makeInviteCode($tenant);

    $response = $this->postJson('/api/device/register', [
        'name' => 'Test Device',
        'android_device_id' => 'unique-android-id-123',
        'invite_code' => $inviteCode->code,
    ]);

    $response->assertCreated();

    $this->assertDatabaseHas('devices', [
        'android_device_id' => 'unique-android-id-123',
        'name' => 'Test Device',
        'is_active' => true,
    ]);
});

it('consumes the invite code after successful registration', function () {
    $tenant = Tenant::factory()->createQuietly();
    $inviteCode = makeInviteCode($tenant);

    $this->postJson('/api/device/register', [
        'name' => 'Test Device',
        'invite_code' => $inviteCode->code,
    ]);

    $inviteCode->refresh();
    expect($inviteCode->isUsed())->toBeTrue();
    expect($inviteCode->used_at)->not->toBeNull();
});

it('rejects a second registration with the same invite code', function () {
    $tenant = Tenant::factory()->createQuietly();
    $inviteCode = makeInviteCode($tenant);

    // First registration — succeeds
    $this->postJson('/api/device/register', [
        'name' => 'Device One',
        'invite_code' => $inviteCode->code,
    ])->assertCreated();

    // Second registration with same code — fails
    $this->postJson('/api/device/register', [
        'name' => 'Device Two',
        'invite_code' => $inviteCode->code,
    ])->assertUnauthorized()->assertJson([
        'message' => 'This invite code has already been used.',
    ]);
});

it('reuses existing device when android_device_id already exists', function () {
    $tenant = Tenant::factory()->createQuietly();
    $inviteCode = makeInviteCode($tenant);

    $existing = Device::factory()->create([
        'android_device_id' => 'known-device-id',
        'name' => 'Old Name',
        'token' => 'old-token-value',
        'is_active' => false,
    ]);

    $response = $this->postJson('/api/device/register', [
        'name' => 'New Name',
        'android_device_id' => 'known-device-id',
        'invite_code' => $inviteCode->code,
    ]);

    $response->assertCreated()->assertJson([
        'device_id' => $existing->id,
        'name' => 'New Name',
        'is_active' => true,
    ]);

    // Same row, updated fields — no duplicate created
    $this->assertDatabaseCount('devices', 1);
    $this->assertDatabaseHas('devices', [
        'id' => $existing->id,
        'name' => 'New Name',
        'is_active' => true,
    ]);

    // Token was regenerated
    $existing->refresh();
    expect($existing->token)->not->toBe('old-token-value');
});

it('reactivates a previously inactive device on re-registration', function () {
    $tenant = Tenant::factory()->createQuietly();
    $inviteCode = makeInviteCode($tenant);

    $existing = Device::factory()->inactive()->create([
        'android_device_id' => 'zombie-device',
    ]);

    $response = $this->postJson('/api/device/register', [
        'name' => 'Reactivated',
        'android_device_id' => 'zombie-device',
        'invite_code' => $inviteCode->code,
    ]);

    $response->assertCreated()->assertJson([
        'device_id' => $existing->id,
        'is_active' => true,
    ]);

    $this->assertDatabaseHas('devices', [
        'id' => $existing->id,
        'is_active' => true,
    ]);
});

it('does not affect other devices when reusing one', function () {
    $tenant = Tenant::factory()->createQuietly();
    $inviteCode = makeInviteCode($tenant);

    $deviceA = Device::factory()->create([
        'android_device_id' => 'device-a',
        'name' => 'Device A',
    ]);

    $deviceB = Device::factory()->create([
        'android_device_id' => 'device-b',
        'name' => 'Device B',
    ]);

    $this->postJson('/api/device/register', [
        'name' => 'Device A Updated',
        'android_device_id' => 'device-a',
        'invite_code' => $inviteCode->code,
    ]);

    $deviceB->refresh();
    expect($deviceB->name)->toBe('Device B');
});

it('requires name field', function () {
    $response = $this->postJson('/api/device/register', []);

    $response->assertUnprocessable()->assertJsonValidationErrors('name');
});

it('can register a device with an invite code that has no tenant', function () {
    $inviteCode = DeviceInviteCode::factory()->create();

    $response = $this->postJson('/api/device/register', [
        'name' => 'No Tenant Device',
        'invite_code' => $inviteCode->code,
    ]);

    $response->assertCreated();

    $this->assertDatabaseHas('devices', [
        'name' => 'No Tenant Device',
        'is_active' => true,
    ]);

    $response->assertJsonMissing(['tenant_id']);
});
