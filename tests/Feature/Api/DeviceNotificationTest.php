<?php

use App\Models\Device;
use App\Models\PaymentNotification;
use App\Models\SystemConfig;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    SystemConfig::create(['group' => 'reconciliation', 'key' => 'regex_bdv', 'value' => '/Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d-]+)\s+hora:\s+(?<time>[\d:]+)/i', 'type' => 'string']);
});

it('creates notification with server-computed dedup hash and no alert', function () {
    $device = Device::factory()->create(['token' => 'valid-token-for-test']);
    $rawBody = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40';

    $response = $this->withHeaders(['X-Device-Token' => 'valid-token-for-test'])
        ->postJson('/api/device/notifications', [
            'bank_code' => 'bdv',
            'raw_body' => $rawBody,
        ]);

    $response->assertStatus(201);

    $expectedHash = PaymentNotification::computeDedupHash('bdv', $rawBody);

    $this->assertDatabaseHas('payment_notifications', [
        'device_id' => $device->id,
        'bank_code' => 'bdv',
        'raw_text' => $rawBody,
        'dedup_hash' => $expectedHash,
        'source_type' => 'android_push',
    ]);

    Notification::assertNothingSent();
});

it('returns duplicate_ignored on duplicate server hash', function () {
    $device = Device::factory()->create(['token' => 'valid-token-for-test-2']);
    $rawBody = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40';

    $payload = [
        'bank_code' => 'bdv',
        'raw_body' => $rawBody,
    ];

    // First request creates the notification
    $first = $this->withHeaders(['X-Device-Token' => 'valid-token-for-test-2'])
        ->postJson('/api/device/notifications', $payload);
    $first->assertStatus(201);

    // Second request — same server hash → UNIQUE constraint violation → duplicate_ignored
    $second = $this->withHeaders(['X-Device-Token' => 'valid-token-for-test-2'])
        ->postJson('/api/device/notifications', $payload);
    $second->assertStatus(200);
    $second->assertJson(['status' => 'duplicate_ignored']);
});
