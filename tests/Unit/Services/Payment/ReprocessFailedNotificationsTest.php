<?php

use App\Jobs\IngestPaymentNotification;
use App\Models\PaymentNotification;
use Illuminate\Support\Facades\Bus;

/**
 * TDD mode: Strict — write test first, then implement.
 * Tests for the ReprocessFailedNotifications command.
 */
beforeEach(function () {
    // Create failed notifications
    PaymentNotification::forceCreate([
        'bank_code' => 'bdv',
        'raw_text' => 'Failed notification 1',
        'dedup_hash' => hash('sha256', 'bdv|failed1'),
        'parse_status' => 'failed',
    ]);

    PaymentNotification::forceCreate([
        'bank_code' => 'bdv',
        'raw_text' => 'Failed notification 2',
        'dedup_hash' => hash('sha256', 'bdv|failed2'),
        'parse_status' => 'failed',
    ]);

    // Create a parsed notification (should NOT be reprocessed)
    PaymentNotification::forceCreate([
        'bank_code' => 'bdv',
        'raw_text' => 'Parsed notification',
        'dedup_hash' => hash('sha256', 'bdv|parsed'),
        'parse_status' => 'parsed',
    ]);
});

// ─── Dispatches jobs for failed notifications ───

test('reprocess command dispatches IngestPaymentNotification for failed notifications', function () {
    Bus::fake();

    $exitCode = Artisan::call('reconciliation:reprocess');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('2');

    Bus::assertDispatched(IngestPaymentNotification::class, 2);
});

// ─── Respects custom parse-status option ───

test('reprocess command with custom parse-status option', function () {
    Bus::fake();

    $exitCode = Artisan::call('reconciliation:reprocess', [
        '--parse-status' => 'parsed',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('1');

    Bus::assertDispatched(IngestPaymentNotification::class, 1);
});

// ─── No matching notifications ───

test('reprocess command reports zero when no matching notifications', function () {
    Bus::fake();

    // Delete the failed notifications, keep only parsed
    PaymentNotification::where('parse_status', 'failed')->delete();

    $exitCode = Artisan::call('reconciliation:reprocess');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('0');

    Bus::assertNotDispatched(IngestPaymentNotification::class);
});
