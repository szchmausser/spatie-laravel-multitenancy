<?php

use App\Enums\CancellationType;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SystemConfig;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * TDD mode: Strict — write test first, then implement.
 * Tests for the ExpirePendingPayments command.
 */
beforeEach(function () {
    SystemConfig::create([
        'group' => 'reconciliation',
        'key' => 'reconciliation.match_window_hours',
        'value' => '72',
        'type' => 'integer',
    ]);

    $this->tenant = Tenant::factory()->createQuietly();
    $this->order = Order::factory()->createQuietly(['tenant_id' => $this->tenant->id]);
});

afterEach(function () {
    Cache::forget('system_config.reconciliation.match_window_hours');
});

// ─── Expires old pending payments ───

test('command expires pending payments older than match_window_hours + 24h buffer', function () {
    // Payment created 97 hours ago — match_window=72, buffer=24, so 96h cutoff
    // 97 > 96 → should be expired
    $oldPayment = Payment::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'order_id' => $this->order->id,
        'amount_cents' => 5000,
        'transaction_id' => 'REF001',
        'status' => PaymentStatus::Pending,
        'created_at' => now()->subHours(97),
    ]);

    $exitCode = Artisan::call('payments:expire-pending');

    $oldPayment->refresh();
    expect($exitCode)->toBe(0);
    expect($oldPayment->status)->toBe(PaymentStatus::Cancelled);
    expect($oldPayment->cancellation_type)->toBe(CancellationType::SystemExpired);
    expect($oldPayment->cancellation_reason)->toBe('Pago expiró sin conciliación automática');
    expect($oldPayment->cancelled_by)->toBeNull();
});

// ─── Respects the time window ───

test('command does not expire payments within match window', function () {
    // Payment created 48 hours ago — well within the expiry window (72 + 24 = 96h)
    $recentPayment = Payment::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'order_id' => $this->order->id,
        'amount_cents' => 5000,
        'transaction_id' => 'REF002',
        'status' => PaymentStatus::Pending,
        'created_at' => now()->subHours(48),
    ]);

    Artisan::call('payments:expire-pending');

    $recentPayment->refresh();
    expect($recentPayment->status)->toBe(PaymentStatus::Pending);
});

// ─── Reports how many were cancelled ───

test('command reports count of expired payments', function () {
    Payment::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'order_id' => $this->order->id,
        'amount_cents' => 5000,
        'transaction_id' => 'REF003',
        'status' => PaymentStatus::Pending,
        'created_at' => now()->subHours(97),
    ]);

    Payment::factory()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'order_id' => $this->order->id,
        'amount_cents' => 6000,
        'transaction_id' => 'REF004',
        'status' => PaymentStatus::Pending,
        'created_at' => now()->subHours(100),
    ]);

    $exitCode = Artisan::call('payments:expire-pending');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('2');
});

// ─── Does not expire non-pending payments ───

test('command does not expire verified or cancelled payments', function () {
    // Verified payment, old
    Payment::factory()->verified()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'order_id' => $this->order->id,
        'amount_cents' => 5000,
        'transaction_id' => 'REF005',
        'created_at' => now()->subHours(100),
    ]);

    // Cancelled payment, old
    Payment::factory()->cancelled()->createQuietly([
        'tenant_id' => $this->tenant->id,
        'order_id' => $this->order->id,
        'amount_cents' => 5000,
        'transaction_id' => 'REF006',
        'created_at' => now()->subHours(100),
    ]);

    Artisan::call('payments:expire-pending');

    // Both should retain their original statuses
    expect(Payment::where('status', PaymentStatus::Cancelled)->count())->toBe(1);
    expect(Payment::where('status', PaymentStatus::Verified)->count())->toBe(1);
});
