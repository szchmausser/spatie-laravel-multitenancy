<?php

use App\Enums\PaymentStatus;
use App\Events\PaymentVerified;
use App\Jobs\IngestPaymentNotification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMatch;
use App\Models\PaymentNotification;
use App\Models\SystemConfig;
use App\Models\Tenant;
use App\Services\Payment\PaymentService;
use App\Services\Payment\ReconciliationOrchestrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

/**
 * TDD mode: Strict — write test first, then implement.
 * These tests verify the IngestPaymentNotification job.
 */
beforeEach(function () {
    // Set reconciliation config for tests
    SystemConfig::create([
        'group' => 'reconciliation',
        'key' => 'reconciliation.match_window_hours',
        'value' => '72',
        'type' => 'integer',
    ]);
    SystemConfig::create([
        'group' => 'reconciliation',
        'key' => 'reconciliation.shadow_mode_channels',
        'value' => '[]',
        'type' => 'json',
    ]);

    // Real BDV regex pattern (backward compat for SMS path)
    SystemConfig::create([
        'group' => 'reconciliation',
        'key' => 'regex_bdv',
        'value' => '/Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d-]+)\s+hora:\s+(?<time>[\d:]+)/i',
        'type' => 'string',
    ]);

    // Channel-specific regex for bank-app (matching the column default)
    SystemConfig::create([
        'group' => 'reconciliation',
        'key' => 'regex_bdv_bank-app',
        'value' => '/Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d-]+)\s+hora:\s+(?<time>[\d:]+)/i',
        'type' => 'string',
    ]);

    // Channel-specific regex backward compat (Android push uses same pattern)
    SystemConfig::create([
        'group' => 'reconciliation',
        'key' => 'regex_bdv_android_push',
        'value' => '/Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d-]+)\s+hora:\s+(?<time>[\d:]+)/i',
        'type' => 'string',
    ]);

    $this->paymentService = new PaymentService([]);
    $this->orchestrator = new ReconciliationOrchestrator($this->paymentService);

    // Create notification
    $notification = new PaymentNotification;
    $notification->bank_code = 'bdv';
    $notification->raw_text = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40';
    $notification->dedup_hash = PaymentNotification::computeDedupHash('bdv', 'test body');
    $notification->parse_status = 'pending';
    $notification->save();
    $notification->refresh();

    $this->notification = $notification;
});

afterEach(function () {
    Cache::forget('system_config.reconciliation.shadow_mode_channels');
    Cache::forget('system_config.reconciliation.match_window_hours');
    Cache::forget('system_config.regex_bdv');
    Cache::forget('system_config.regex_bdv_bank-app');
    Cache::forget('system_config.regex_bdv_android_push');
});

// ─── Successful parse → match → parse_status = parsed ───

test('job processes notification — successful parse creates match and updates status to parsed', function () {
    Event::fake([PaymentVerified::class]);

    $tenant = Tenant::factory()->createQuietly();
    $order = Order::factory()->createQuietly(['tenant_id' => $tenant->id]);
    $payment = Payment::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 300000,
        'transaction_id' => '006236568762',
        'status' => PaymentStatus::Pending,
        'created_at' => now()->subHours(2),
    ]);

    $job = new IngestPaymentNotification($this->notification);
    $job->handle();

    // Refresh notification
    $this->notification->refresh();

    // Parse status should be 'parsed'
    expect($this->notification->parse_status)->toBe('parsed');
    expect($this->notification->parsed_at)->not->toBeNull();
    expect($this->notification->parsed_data)->toBeArray();
    expect($this->notification->parsed_data['amount_cents'])->toBe(300000);

    // PaymentMatch should exist
    $match = PaymentMatch::where('payment_notification_id', $this->notification->id)->first();
    expect($match)->not->toBeNull();
    expect($match->parsed_reference)->toBe('006236568762');
    expect($match->parsed_amount_cents)->toBe(300000);
    expect($match->match_status)->toBe('matched');

    // Payment should be verified
    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Verified);

    // PaymentVerified event should be dispatched
    Event::assertDispatched(PaymentVerified::class, fn (PaymentVerified $e) => $e->payment->id === $payment->id);
});

// ─── Parse failure → parse_status = failed, no match ───

test('job with parse failure marks notification as failed and does not attempt match', function () {
    // Create a notification with unparseable text
    $badNotification = new PaymentNotification;
    $badNotification->bank_code = 'bdv';
    $badNotification->raw_text = 'This text does not match any regex pattern';
    $badNotification->dedup_hash = PaymentNotification::computeDedupHash('bdv', 'unparseable');
    $badNotification->parse_status = 'pending';
    $badNotification->save();
    $badNotification->refresh();

    $job = new IngestPaymentNotification($badNotification);
    $job->handle();

    $badNotification->refresh();

    expect($badNotification->parse_status)->toBe('failed');
    expect($badNotification->parse_error)->not->toBeNull();
    expect($badNotification->parse_error)->toBe('Regex did not match');

    // No PaymentMatch should be created
    expect(PaymentMatch::where('payment_notification_id', $badNotification->id)->count())->toBe(0);
});

// ─── Shadow mode ON → no PaymentVerified event ───

test('job with shadow mode on does not dispatch PaymentVerified event even with match', function () {
    Event::fake([PaymentVerified::class]);

    // Set shadow mode on for bank-app (notification default source_type)
    SystemConfig::where('key', 'reconciliation.shadow_mode_channels')
        ->update(['value' => json_encode(['bank-app'])]);
    Cache::forget('system_config.reconciliation.shadow_mode_channels');

    $tenant = Tenant::factory()->createQuietly();
    $order = Order::factory()->createQuietly(['tenant_id' => $tenant->id]);
    $payment = Payment::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 300000,
        'transaction_id' => '006236568762',
        'status' => PaymentStatus::Pending,
        'created_at' => now()->subHours(2),
    ]);

    $job = new IngestPaymentNotification($this->notification);
    $job->handle();

    $this->notification->refresh();
    expect($this->notification->parse_status)->toBe('parsed');

    // Payment should still be Pending (shadow mode)
    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Pending);

    // No PaymentVerified event should be dispatched
    Event::assertNotDispatched(PaymentVerified::class);
});
