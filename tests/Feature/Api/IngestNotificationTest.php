<?php

use App\Models\Device;
use App\Models\SystemConfig;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    SystemConfig::create(['group' => 'reconciliation', 'key' => 'regex_bdv_bank-app', 'value' => '/Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d-]+)\s+hora:\s+(?<time>[\d:]+)/i', 'type' => 'string']);
    SystemConfig::create(['group' => 'reconciliation', 'key' => 'regex_bdv_sms', 'value' => '/Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d-]+)\s+hora:\s+(?<time>[\d:]+)/i', 'type' => 'string']);
});

it('creates notification with correct source_type via ingest endpoint', function () {
    $device = Device::factory()->create(['token' => 'ingest-test-token']);
    $rawBody = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40';

    $response = $this->withHeaders(['X-Device-Token' => 'ingest-test-token'])
        ->postJson('/api/ingest/bank-app', [
            'bank_code' => 'bdv',
            'raw_body' => $rawBody,
        ]);

    $response->assertStatus(201);
    $response->assertJson(['status' => 'created']);

    $this->assertDatabaseHas('payment_notifications', [
        'device_id' => $device->id,
        'bank_code' => 'bdv',
        'raw_text' => $rawBody,
        'source_type' => 'bank-app',
        'parse_status' => 'pending',
    ]);
});

it('returns duplicate_ignored on duplicate hash via ingest endpoint', function () {
    $device = Device::factory()->create(['token' => 'ingest-test-token-dup']);
    $rawBody = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40';

    $payload = [
        'bank_code' => 'bdv',
        'raw_body' => $rawBody,
    ];

    // First request creates the notification
    $first = $this->withHeaders(['X-Device-Token' => 'ingest-test-token-dup'])
        ->postJson('/api/ingest/bank-app', $payload);
    $first->assertStatus(201);

    // Second request — same hash → UNIQUE constraint → duplicate_ignored
    $second = $this->withHeaders(['X-Device-Token' => 'ingest-test-token-dup'])
        ->postJson('/api/ingest/bank-app', $payload);
    $second->assertStatus(200);
    $second->assertJson(['status' => 'duplicate_ignored']);
});

it('returns 422 for invalid source type', function () {
    $device = Device::factory()->create(['token' => 'ingest-test-token-422']);

    $response = $this->withHeaders(['X-Device-Token' => 'ingest-test-token-422'])
        ->postJson('/api/ingest/invalid-source', [
            'bank_code' => 'bdv',
            'raw_body' => 'Some body',
        ]);

    $response->assertStatus(422);
    $response->assertJson(['message' => 'Invalid source type: invalid-source']);
});

it('returns 401 without auth header', function () {
    $response = $this->postJson('/api/ingest/bank-app', [
        'bank_code' => 'bdv',
        'raw_body' => 'Some body',
    ]);

    $response->assertStatus(401);
});

it('returns 404 for old removed /api/notifications route', function () {
    Device::factory()->create(['token' => 'old-route-test-token']);

    $response = $this->withHeaders(['X-Device-Token' => 'old-route-test-token'])
        ->postJson('/api/notifications', [
            'bank_code' => 'bdv',
            'raw_body' => 'Some body',
        ]);

    $response->assertStatus(404);
});
