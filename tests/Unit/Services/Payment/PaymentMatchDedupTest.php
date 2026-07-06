<?php

use App\Enums\SourceType;
use App\Models\PaymentMatch;
use App\Models\PaymentNotification;
use App\Services\Payment\ParsedPayment;
use Carbon\Carbon;

/**
 * Tests for PaymentMatch::createFromParsed() 4-step dedup algorithm.
 *
 * Steps:
 * 1. Idempotency — same notification → return existing
 * 2. Same reference, unmatched exists → reuse (link notification)
 * 3. Same reference, matched exists → create duplicate_attempt
 * 4. No match (or null reference) → create new unmatched
 */
beforeEach(function () {
    $this->notification1 = PaymentNotification::forceCreate([
        'bank_code' => 'bdv',
        'raw_text' => 'First notification body',
        'dedup_hash' => hash('sha256', 'bdv|first'),
        'parse_status' => 'pending',
        'source_type' => SourceType::BankApp,
    ]);

    $this->notification2 = PaymentNotification::forceCreate([
        'bank_code' => 'bdv',
        'raw_text' => 'Second notification body (different)',
        'dedup_hash' => hash('sha256', 'bdv|second'),
        'parse_status' => 'pending',
        'source_type' => SourceType::BankApp,
    ]);

    $this->parsed = new ParsedPayment(
        amountCents: 300000,
        reference: 'REF123',
        senderPhoneLast4: '4567',
        parsedAt: Carbon::now(),
    );

    $this->parsedNoRef = new ParsedPayment(
        amountCents: 500000,
        reference: null,
        senderPhoneLast4: '1234',
        parsedAt: Carbon::now(),
    );
});

// ─── Step 4: No match → create new unmatched ───

test('createFromParsed creates unmatched match when no existing match', function () {
    $match = PaymentMatch::createFromParsed($this->notification1, $this->parsed);

    expect($match->match_status)->toBe('unmatched');
    expect($match->parsed_reference)->toBe('REF123');
    expect($match->parsed_amount_cents)->toBe(300000);
    expect($match->payment_notification_id)->toBe($this->notification1->id);
    expect($match->payment_id)->toBeNull();
});

// ─── Step 1: Idempotency — same notification → return existing ───

test('createFromParsed returns existing match for same notification (idempotency)', function () {
    $first = PaymentMatch::createFromParsed($this->notification1, $this->parsed);
    $second = PaymentMatch::createFromParsed($this->notification1, $this->parsed);

    expect($second->id)->toBe($first->id);
    expect(PaymentMatch::count())->toBe(1);
});

// ─── Step 2: Same reference, unmatched exists → reuse ───

test('createFromParsed reuses existing unmatched match for same reference', function () {
    $first = PaymentMatch::createFromParsed($this->notification1, $this->parsed);
    expect($first->fresh()->match_status)->toBe('unmatched');

    $second = PaymentMatch::createFromParsed($this->notification2, $this->parsed);

    // Returns the same match, not a new one
    expect($second->id)->toBe($first->id);
    // payment_notification_id is updated to the new notification
    expect($second->fresh()->payment_notification_id)->toBe($this->notification2->id);
    expect(PaymentMatch::count())->toBe(1);
});

// ─── Step 3: Same reference, matched exists → duplicate_attempt ───

test('createFromParsed creates duplicate_attempt when matched match exists for same reference', function () {
    $first = PaymentMatch::createFromParsed($this->notification1, $this->parsed);
    // Manually promote to matched (normally done by the orchestrator)
    $first->forceFill(['match_status' => 'matched', 'payment_id' => null])->save();

    $second = PaymentMatch::createFromParsed($this->notification2, $this->parsed);

    expect($second->id)->not->toBe($first->id);
    expect($second->match_status)->toBe('duplicate_attempt');
    expect($second->parsed_reference)->toBe('REF123');
    expect($second->parsed_amount_cents)->toBe(300000);
    expect($second->payment_notification_id)->toBe($this->notification2->id);
    expect(PaymentMatch::count())->toBe(2);
});

// ─── Step 4: Null reference → creates new unmatched (WITHOUT going through dedup) ───

test('createFromParsed creates unmatched match when parsed reference is null', function () {
    $match = PaymentMatch::createFromParsed($this->notification1, $this->parsedNoRef);

    expect($match->match_status)->toBe('unmatched');
    expect($match->parsed_reference)->toBeNull();
    expect($match->parsed_amount_cents)->toBe(500000);
    expect($match->payment_notification_id)->toBe($this->notification1->id);
    expect($match->payment_id)->toBeNull();
});

// ─── Empty string reference also skips dedup (same as null) ───

test('createFromParsed creates unmatched match when parsed reference is empty string', function () {
    $parsedEmptyRef = new ParsedPayment(
        amountCents: 100000,
        reference: '',
        senderPhoneLast4: '9999',
        parsedAt: Carbon::now(),
    );

    $match = PaymentMatch::createFromParsed($this->notification1, $parsedEmptyRef);

    expect($match->match_status)->toBe('unmatched');
    expect($match->parsed_reference)->toBe('');
    expect(PaymentMatch::count())->toBe(1);
});

// ─── Two different references → two separate unmatched matches ───

test('createFromParsed creates separate matches for different references', function () {
    $parsedA = new ParsedPayment(
        amountCents: 100000,
        reference: 'REF-A',
        senderPhoneLast4: '1111',
        parsedAt: Carbon::now(),
    );

    $parsedB = new ParsedPayment(
        amountCents: 200000,
        reference: 'REF-B',
        senderPhoneLast4: '2222',
        parsedAt: Carbon::now(),
    );

    $matchA = PaymentMatch::createFromParsed($this->notification1, $parsedA);
    $matchB = PaymentMatch::createFromParsed($this->notification2, $parsedB);

    expect($matchA->id)->not->toBe($matchB->id);
    expect(PaymentMatch::count())->toBe(2);
    expect($matchA->match_status)->toBe('unmatched');
    expect($matchB->match_status)->toBe('unmatched');
});
