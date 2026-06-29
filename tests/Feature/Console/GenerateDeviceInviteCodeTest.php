<?php

use App\Models\DeviceInviteCode;
use App\Models\Landlord;

it('generates an invite code without any options', function () {
    $this->artisan('device:generate-invite')
        ->assertSuccessful();

    $this->assertDatabaseCount('device_invite_codes', 1);

    $code = DeviceInviteCode::first();
    expect($code)->not->toBeNull();
    expect($code->code)->toMatch('/^INV-[A-Z0-9]{8}$/');
    expect($code->expires_at)->not->toBeNull(); // default: 7 days
});

it('generates an invite code with custom days option', function () {
    $this->artisan('device:generate-invite', ['--days' => 30])
        ->assertSuccessful();

    $code = DeviceInviteCode::first();
    expect($code->expires_at)->not->toBeNull();
});

it('generates an invite code that never expires when days is 0', function () {
    $this->artisan('device:generate-invite', ['--days' => 0])
        ->assertSuccessful();

    $code = DeviceInviteCode::first();
    expect($code->expires_at)->toBeNull();
});

it('generates an invite code with created-by option', function () {
    $admin = Landlord::factory()->create();

    $this->artisan('device:generate-invite', ['--created-by' => $admin->id])
        ->assertSuccessful();

    $code = DeviceInviteCode::first();
    expect($code->created_by)->toBe($admin->id);
});
