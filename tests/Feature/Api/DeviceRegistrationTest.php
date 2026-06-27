<?php

use App\Models\Device;

it('creates a new device when no android_device_id is provided', function () {
    $response = $this->postJson('/api/device/register', [
        'name' => 'Test Device',
    ]);

    $response->assertCreated()->assertJsonStructure([
        'device_id',
        'token',
        'name',
        'is_active',
    ]);

    $this->assertDatabaseHas('devices', [
        'name' => 'Test Device',
        'is_active' => true,
    ]);
});

it('creates a new device when android_device_id is novel', function () {
    $response = $this->postJson('/api/device/register', [
        'name' => 'Test Device',
        'android_device_id' => 'unique-android-id-123',
    ]);

    $response->assertCreated();

    $this->assertDatabaseHas('devices', [
        'android_device_id' => 'unique-android-id-123',
        'name' => 'Test Device',
        'is_active' => true,
    ]);
});

it('reuses existing device when android_device_id already exists', function () {
    $existing = Device::factory()->create([
        'android_device_id' => 'known-device-id',
        'name' => 'Old Name',
        'token' => 'old-token-value',
        'is_active' => false,
    ]);

    $response = $this->postJson('/api/device/register', [
        'name' => 'New Name',
        'android_device_id' => 'known-device-id',
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
    $existing = Device::factory()->inactive()->create([
        'android_device_id' => 'zombie-device',
    ]);

    $response = $this->postJson('/api/device/register', [
        'name' => 'Reactivated',
        'android_device_id' => 'zombie-device',
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
    ]);

    $deviceB->refresh();
    expect($deviceB->name)->toBe('Device B');
});

it('requires name field', function () {
    $response = $this->postJson('/api/device/register', []);

    $response->assertUnprocessable()->assertJsonValidationErrors('name');
});
