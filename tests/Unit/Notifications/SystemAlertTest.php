<?php

use App\Models\Landlord;
use App\Notifications\SystemAlert;

it('sends via database channel', function () {
    $notification = new SystemAlert('test_type', 'Test message');
    $admin = Landlord::factory()->createQuietly();

    expect($notification->via($admin))->toBe(['database']);
});

it('includes category system and type message in toArray', function () {
    $notification = new SystemAlert('parser_failed', 'Failed to parse notification');
    $admin = Landlord::factory()->createQuietly();

    $data = $notification->toArray($admin);

    expect($data['category'])->toBe('system');
    expect($data['type'])->toBe('parser_failed');
    expect($data['message'])->toBe('Failed to parse notification');
    expect($data)->toHaveKey('severity');
});
