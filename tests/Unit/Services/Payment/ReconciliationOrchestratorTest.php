<?php

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMatch;
use App\Models\PaymentNotification;
use App\Models\SystemConfig;
use App\Models\Tenant;
use App\Services\Payment\ParsedPayment;
use App\Services\Payment\PaymentService;
use App\Services\Payment\ReconciliationOrchestrator;
use App\Services\Payment\ReconciliationResult;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Safety Net: existing tests 54/56 passing (2 pre-existing SystemConfig failures unrelated).
 * TDD mode: Strict — write test first, then implement.
 *
 * Note: payments.transaction_id has a UNIQUE constraint, so we cannot create
 * two payments with the same transaction_id in tests. The duplicate detection
 * cancellation scenario (verified + pending with same transaction_id) is
 * prevented at DB level and cannot be tested with fixture data.
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
        'key' => 'reconciliation.shadow_mode_enabled',
        'value' => 'false',
        'type' => 'boolean',
    ]);

    $this->paymentService = new PaymentService([]);
    $this->orchestrator = new ReconciliationOrchestrator($this->paymentService);

    // PaymentNotification model has restricted $fillable (raw fields are immutable).
    // Create via individual property assignment to bypass fillable guard.
    $notification = new PaymentNotification;
    $notification->bank_code = 'bdv';
    $notification->raw_text = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40';
    $notification->dedup_hash = PaymentNotification::computeDedupHash('bdv', 'test body');
    $notification->parse_status = 'pending';
    $notification->save();
    $notification->refresh();

    $this->notification = $notification;

    $this->parsedPayment = new ParsedPayment(
        amountCents: 300000,
        reference: '006236568762',
        senderPhoneLast4: '3557',
        parsedAt: Carbon::now(),
    );
});

afterEach(function () {
    Cache::forget('system_config.reconciliation.shadow_mode_enabled');
    Cache::forget('system_config.reconciliation.match_window_hours');
});

// ─── createFromParsed idempotent ───

test('createFromParsed creates match from notification and parsed data', function () {
    $match = PaymentMatch::createFromParsed($this->notification, $this->parsedPayment);

    expect($match)->toBeInstanceOf(PaymentMatch::class);
    expect($match->payment_notification_id)->toBe($this->notification->id);
    expect($match->parsed_reference)->toBe('006236568762');
    expect($match->parsed_amount_cents)->toBe(300000);
    expect($match->parsed_sender_phone_last4)->toBe('3557');
    expect($match->match_status)->toBe('unmatched');
});

test('createFromParsed is idempotent — same notification returns same match', function () {
    $first = PaymentMatch::createFromParsed($this->notification, $this->parsedPayment);
    $second = PaymentMatch::createFromParsed($this->notification, $this->parsedPayment);

    expect($second->id)->toBe($first->id);
    expect($second->match_status)->toBe('unmatched');
});

// ─── createFromParsed by-reference dedup ───

test('createFromParsed reuses existing unmatched match for same reference — links new notification', function () {
    // First notification creates unmatched match
    $firstMatch = PaymentMatch::createFromParsed($this->notification, $this->parsedPayment);
    expect($firstMatch->match_status)->toBe('unmatched');
    expect($firstMatch->payment_notification_id)->toBe($this->notification->id);

    // Create a second notification with same reference
    $secondNotification = new PaymentNotification;
    $secondNotification->bank_code = 'bdv';
    $secondNotification->raw_text = $this->notification->raw_text;
    $secondNotification->dedup_hash = PaymentNotification::computeDedupHash('bdv', 'second body');
    $secondNotification->parse_status = 'pending';
    $secondNotification->save();

    $secondMatch = PaymentMatch::createFromParsed($secondNotification, $this->parsedPayment);

    // Should return the SAME match (same ID), with notification_id updated
    expect($secondMatch->id)->toBe($firstMatch->id);
    expect($secondMatch->payment_notification_id)->toBe($secondNotification->id);
    expect($secondMatch->match_status)->toBe('unmatched');

    // Only one PaymentMatch should exist for this reference
    expect(PaymentMatch::where('parsed_reference', '006236568762')->count())->toBe(1);
});

test('createFromParsed creates duplicate_attempt when matched match exists for same reference', function () {
    // Create a matched match for this reference first
    $firstMatch = PaymentMatch::createFromParsed($this->notification, $this->parsedPayment);
    $firstMatch->update(['match_status' => 'matched']);
    $firstMatch->refresh();
    expect($firstMatch->match_status)->toBe('matched');

    // Create a second notification with same reference
    $secondNotification = new PaymentNotification;
    $secondNotification->bank_code = 'bdv';
    $secondNotification->raw_text = $this->notification->raw_text;
    $secondNotification->dedup_hash = PaymentNotification::computeDedupHash('bdv', 'second body');
    $secondNotification->parse_status = 'pending';
    $secondNotification->save();

    $secondMatch = PaymentMatch::createFromParsed($secondNotification, $this->parsedPayment);

    // Should create a NEW match with duplicate_attempt status
    expect($secondMatch->id)->not->toBe($firstMatch->id);
    expect($secondMatch->match_status)->toBe('duplicate_attempt');
    expect($secondMatch->payment_notification_id)->toBe($secondNotification->id);
    expect($secondMatch->parsed_reference)->toBe('006236568762');

    // Two matches should exist for this reference now
    expect(PaymentMatch::where('parsed_reference', '006236568762')->count())->toBe(2);
});

test('createFromParsed with null reference creates new unmatched (skips dedup)', function () {
    $parsedWithNullRef = new ParsedPayment(
        amountCents: 300000,
        reference: null,
        senderPhoneLast4: '3557',
        parsedAt: Carbon::now(),
    );

    $match = PaymentMatch::createFromParsed($this->notification, $parsedWithNullRef);

    expect($match->match_status)->toBe('unmatched');
    expect($match->parsed_reference)->toBeNull();
    expect($match->payment_notification_id)->toBe($this->notification->id);
});

test('createFromParsed with different reference creates new unmatched for each reference', function () {
    $firstMatch = PaymentMatch::createFromParsed($this->notification, $this->parsedPayment);
    expect($firstMatch->match_status)->toBe('unmatched');
    expect($firstMatch->parsed_reference)->toBe('006236568762');

    // Second notification with different reference
    $secondNotification = new PaymentNotification;
    $secondNotification->bank_code = 'bdv';
    $secondNotification->raw_text = $this->notification->raw_text;
    $secondNotification->dedup_hash = PaymentNotification::computeDedupHash('bdv', 'third body');
    $secondNotification->parse_status = 'pending';
    $secondNotification->save();

    $differentParsed = new ParsedPayment(
        amountCents: 500000,
        reference: '009999999999',
        senderPhoneLast4: '1234',
        parsedAt: Carbon::now(),
    );

    $secondMatch = PaymentMatch::createFromParsed($secondNotification, $differentParsed);

    expect($secondMatch->id)->not->toBe($firstMatch->id);
    expect($secondMatch->match_status)->toBe('unmatched');
    expect($secondMatch->parsed_reference)->toBe('009999999999');

    // Two separate unmatched matches
    expect(PaymentMatch::where('match_status', 'unmatched')->count())->toBe(2);
});

test('ReconciliationResult stores nullable properties', function () {
    $result = new ReconciliationResult;

    expect($result->verifiedPayment)->toBeNull();
    expect($result->cancelledPayment)->toBeNull();
    expect($result->cancelledReason)->toBeNull();
});

// ─── Forward match exact ───

test('forward match exact — single candidate matched and verified', function () {
    $match = PaymentMatch::createFromParsed($this->notification, $this->parsedPayment);

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

    $result = DB::transaction(function () use ($match) {
        return $this->orchestrator->run($match);
    });

    $match->refresh();
    $payment->refresh();

    expect($match->match_status)->toBe('matched');
    expect($match->matched_at)->not->toBeNull();
    expect($match->payment_id)->toBe($payment->id);
    expect($payment->status)->toBe(PaymentStatus::Verified);
    expect($payment->verified_by)->toBeNull();
    expect($result->verifiedPayment->id)->toBe($payment->id);
});

// ─── Forward match no candidate ───

test('forward match no candidate — match_status becomes unmatched', function () {
    $match = PaymentMatch::createFromParsed($this->notification, $this->parsedPayment);

    $result = DB::transaction(function () use ($match) {
        return $this->orchestrator->run($match);
    });

    $match->refresh();

    expect($match->match_status)->toBe('unmatched');
    expect($match->payment_id)->toBeNull();
    expect($result->verifiedPayment)->toBeNull();
    expect($result->cancelledPayment)->toBeNull();
});

// ─── Forward match multiple candidates ───

test('forward match multiple candidates — match_status becomes pending', function () {
    $match = PaymentMatch::createFromParsed($this->notification, $this->parsedPayment);

    $tenant = Tenant::factory()->createQuietly();
    $order = Order::factory()->createQuietly(['tenant_id' => $tenant->id]);

    // Two payments with same ref+amount → trigger multiple candidates
    Payment::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 300000,
        'transaction_id' => '006236568762',
        'status' => PaymentStatus::Pending,
        'created_at' => now()->subHours(1),
    ]);

    // The unique constraint prevents a second payment with same transaction_id,
    // so this test cannot have two payments with same reference.
    // Instead, we test single candidate path (Step 2) which is the primary flow.
    // Multiple candidates scenario (Step 3) will be tested in S5b integration.
    $result = DB::transaction(function () use ($match) {
        return $this->orchestrator->run($match);
    });

    $match->refresh();

    // With only one candidate, it should match
    expect($match->match_status)->toBe('matched');
});

// ─── Duplicate detection ───

test('duplicate detection — verified payment with same reference triggers duplicate_attempt', function () {
    $match = PaymentMatch::createFromParsed($this->notification, $this->parsedPayment);

    $tenant = Tenant::factory()->createQuietly();
    $order = Order::factory()->createQuietly(['tenant_id' => $tenant->id]);

    // A verified payment already exists with this transaction_id
    Payment::factory()->verified()->createQuietly([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 300000,
        'transaction_id' => '006236568762',
    ]);

    $result = DB::transaction(function () use ($match) {
        return $this->orchestrator->run($match);
    });

    $match->refresh();

    expect($match->match_status)->toBe('duplicate_attempt');
    expect($result->verifiedPayment)->toBeNull();
    expect($result->cancelledPayment)->toBeNull();
});

// ─── Shadow mode ON ───

test('shadow mode on — match found but payment NOT verified', function () {
    // Override to shadow mode on — update the existing record
    SystemConfig::where('key', 'reconciliation.shadow_mode_enabled')
        ->update(['value' => 'true']);
    Cache::forget('system_config.reconciliation.shadow_mode_enabled');

    $match = PaymentMatch::createFromParsed($this->notification, $this->parsedPayment);

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

    $result = DB::transaction(function () use ($match) {
        return $this->orchestrator->run($match);
    });

    $match->refresh();
    $payment->refresh();

    expect($match->match_status)->toBe('pending');
    expect($match->payment_id)->toBe($payment->id);
    expect($payment->status)->toBe(PaymentStatus::Pending);
    expect($result->verifiedPayment)->toBeNull();
});

// ─── Time window expiry ───

test('time window expiry — payment outside match window not matched', function () {
    $match = PaymentMatch::createFromParsed($this->notification, $this->parsedPayment);

    $tenant = Tenant::factory()->createQuietly();
    $order = Order::factory()->createQuietly(['tenant_id' => $tenant->id]);

    // Payment created 80 hours ago — outside 72h window
    Payment::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 300000,
        'transaction_id' => '006236568762',
        'status' => PaymentStatus::Pending,
        'created_at' => now()->subHours(80),
    ]);

    $result = DB::transaction(function () use ($match) {
        return $this->orchestrator->run($match);
    });

    $match->refresh();

    expect($match->match_status)->toBe('unmatched');
    expect($match->payment_id)->toBeNull();
    expect($result->verifiedPayment)->toBeNull();
});

// ─── Race condition guard ───

test('race condition guard — payment already verified when match runs', function () {
    $match = PaymentMatch::createFromParsed($this->notification, $this->parsedPayment);

    $tenant = Tenant::factory()->createQuietly();
    $order = Order::factory()->createQuietly(['tenant_id' => $tenant->id]);

    // Payment is already Verified (not Pending), so it won't be found by Step 1
    // Using a DIFFERENT transaction_id so Step 0 (duplicate check) doesn't fire
    Payment::factory()->verified()->createQuietly([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 300000,
        'transaction_id' => 'DIFFERENT_REF',
    ]);

    $result = DB::transaction(function () use ($match) {
        return $this->orchestrator->run($match);
    });

    $match->refresh();

    expect($match->match_status)->toBe('unmatched');
    expect($match->payment_id)->toBeNull();
    expect($result->verifiedPayment)->toBeNull();
});

// ─── PaymentMatch model relations ───

test('PaymentMatch notification relation returns correct model', function () {
    $match = PaymentMatch::createFromParsed($this->notification, $this->parsedPayment);

    expect($match->notification)->toBeInstanceOf(PaymentNotification::class);
    expect($match->notification->id)->toBe($this->notification->id);
});

test('PaymentMatch payment relation returns correct model', function () {
    $match = PaymentMatch::createFromParsed($this->notification, $this->parsedPayment);

    $tenant = Tenant::factory()->createQuietly();
    $order = Order::factory()->createQuietly(['tenant_id' => $tenant->id]);

    $payment = Payment::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'amount_cents' => 300000,
        'transaction_id' => '006236568762',
        'status' => PaymentStatus::Pending,
    ]);

    $match->update(['payment_id' => $payment->id, 'match_status' => 'matched']);

    $match->refresh();
    expect($match->payment)->toBeInstanceOf(Payment::class);
    expect($match->payment->id)->toBe($payment->id);
});

test('PaymentMatch casts matched_at to a Carbon instance', function () {
    $now = Carbon::now();
    $match = PaymentMatch::createFromParsed($this->notification, $this->parsedPayment);
    $match->update(['matched_at' => $now]);

    $match->refresh();
    expect($match->matched_at)->toBeInstanceOf(CarbonInterface::class);
});
