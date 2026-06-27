<?php

use App\Models\Device;
use App\Models\Landlord;
use App\Models\SystemConfig;
use App\Notifications\SystemAlert;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    // Ensure the system config exists for heartbeat interval
    SystemConfig::updateOrCreate(
        ['group' => 'device', 'key' => 'heartbeat_interval_minutes'],
        ['value' => '5', 'type' => 'integer', 'description' => 'Heartbeat interval'],
    );
});

it('deactivates a device that never sent a heartbeat', function () {
    $device = Device::factory()->create([
        'is_active' => true,
        'last_heartbeat_at' => null,
    ]);

    $this->artisan('devices:check-heartbeats');

    $this->assertDatabaseHas('devices', [
        'id' => $device->id,
        'is_active' => false,
    ]);
});

it('deactivates a device with stale heartbeat beyond timeout', function () {
    // Timeout = interval(5) * 3 = 15 minutes. Stale = 60 min ago.
    $device = Device::factory()->stale(60)->create([
        'is_active' => true,
    ]);

    $this->artisan('devices:check-heartbeats');

    $this->assertDatabaseHas('devices', [
        'id' => $device->id,
        'is_active' => false,
    ]);
});

it('does not deactivate a device with a recent heartbeat', function () {
    $device = Device::factory()->create([
        'is_active' => true,
        'last_heartbeat_at' => now()->subMinutes(2),
    ]);

    $this->artisan('devices:check-heartbeats');

    $this->assertDatabaseHas('devices', [
        'id' => $device->id,
        'is_active' => true,
    ]);
});

it('does not touch already inactive devices', function () {
    $device = Device::factory()->inactive()->create([
        'last_heartbeat_at' => null,
    ]);

    $this->artisan('devices:check-heartbeats');

    // Should remain inactive (was already)
    $this->assertDatabaseHas('devices', [
        'id' => $device->id,
        'is_active' => false,
    ]);
});

it('sends a notification to admins for each deactivated device', function () {
    $admin = Landlord::factory()->create();

    $device = Device::factory()->create([
        'name' => 'Dead Phone',
        'is_active' => true,
        'last_heartbeat_at' => null,
    ]);

    $this->artisan('devices:check-heartbeats');

    Notification::assertSentTo(
        $admin,
        SystemAlert::class,
        fn (SystemAlert $notification) => str_contains($notification->message, 'Dead Phone')
            && str_contains($notification->message, 'Desactivado automáticamente'),
    );
});

it('handles multiple offline devices in one run', function () {
    $device1 = Device::factory()->create([
        'is_active' => true,
        'last_heartbeat_at' => null,
    ]);
    $device2 = Device::factory()->stale(60)->create([
        'is_active' => true,
    ]);

    $this->artisan('devices:check-heartbeats');

    $this->assertDatabaseHas('devices', ['id' => $device1->id, 'is_active' => false]);
    $this->assertDatabaseHas('devices', ['id' => $device2->id, 'is_active' => false]);
});
