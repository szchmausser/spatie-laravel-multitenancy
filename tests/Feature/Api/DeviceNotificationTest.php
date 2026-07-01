<?php

use App\Models\Device;
use App\Models\Landlord;
use App\Models\PaymentNotification;
use App\Models\SystemConfig;
use App\Notifications\SystemAlert;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    SystemConfig::create(['group' => 'reconciliation', 'key' => 'regex_bdv', 'value' => '/Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d-]+)\s+hora:\s+(?<time>[\d:]+)/i', 'type' => 'string']);
});

it('stores notification on hash mismatch and sends SystemAlert', function () {
    $admin = Landlord::factory()->create();
    $device = Device::factory()->create(['token' => 'valid-token-for-test']);
    $rawBody = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40';

    $response = $this->withHeaders(['X-Device-Token' => 'valid-token-for-test'])
        ->postJson('/api/device/notifications', [
            'bank_code' => 'bdv',
            'raw_body' => $rawBody,
            'dedup_hash' => '0000000000000000000000000000000000000000000000000000000000000000',
        ]);

    $response->assertStatus(201);

    $this->assertDatabaseHas('payment_notifications', [
        'device_id' => $device->id,
        'bank_code' => 'bdv',
        'raw_text' => $rawBody,
    ]);

    Notification::assertSentTo(
        $admin,
        SystemAlert::class,
        fn (SystemAlert $notification) => str_contains($notification->type, 'dedup_hash_mismatch')
            || str_contains($notification->message, 'dedup'),
    );
});

it('stores notification on hash match without alert', function () {
    Landlord::factory()->create();
    $device = Device::factory()->create(['token' => 'valid-token-for-test-2']);
    $rawBody = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40';

    $correctHash = PaymentNotification::computeDedupHash('bdv', $rawBody);

    $response = $this->withHeaders(['X-Device-Token' => 'valid-token-for-test-2'])
        ->postJson('/api/device/notifications', [
            'bank_code' => 'bdv',
            'raw_body' => $rawBody,
            'dedup_hash' => $correctHash,
        ]);

    $response->assertStatus(201);

    $this->assertDatabaseHas('payment_notifications', [
        'bank_code' => 'bdv',
        'raw_text' => $rawBody,
    ]);

    Notification::assertNothingSent();
});
