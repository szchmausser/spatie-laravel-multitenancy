<?php

use App\Console\Commands\SimulatePaymentNotification;
use App\Enums\BankCode;
use App\Enums\SourceType;
use App\Models\PaymentNotification;
use App\Models\SystemConfig;
use App\Services\Payment\ParsedPayment;
use Carbon\Carbon;
use Database\Seeders\NotificationSampleSeeder;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    // Per-channel regex patterns (no fallback — sourceType is required)
    SystemConfig::create(['group' => 'reconciliation', 'key' => 'regex_bdv_sms', 'value' => '/Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d-]+)\s+hora:\s+(?<time>[\d:]+)/i', 'type' => 'string']);
    SystemConfig::create(['group' => 'reconciliation', 'key' => 'regex_bdv_bank-app', 'value' => '/Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d-]+)\s+hora:\s+(?<time>[\d:]+)/i', 'type' => 'string']);
    SystemConfig::create(['group' => 'reconciliation', 'key' => 'regex_bnc_sms', 'value' => '/BNC\s+Pago\s+Movil\s+Recibido\s+Bs\.(?<amount>[\d.,]+)\s+Telf\.(?<phone>[\d*]+)\s+Dia:(?<date>[\d\/]+)-(?<time>[\d:]+)\s+Ref:(?<reference>\d+)/i', 'type' => 'string']);
    SystemConfig::create(['group' => 'reconciliation', 'key' => 'regex_bnc_bank-app', 'value' => '/BNC\s+Pago\s+Movil\s+Recibido\s+Bs\.(?<amount>[\d.,]+)\s+Telf\.(?<phone>[\d*]+)\s+Dia:(?<date>[\d\/]+)-(?<time>[\d:]+)\s+Ref:(?<reference>\d+)/i', 'type' => 'string']);
});

// --- Command Tests ---

it('creates a payment notification with correct dedup_hash', function () {
    Artisan::call(SimulatePaymentNotification::class, [
        '--bank' => 'bdv',
        '--amount' => '3000',
        '--reference' => '006236568762',
    ]);

    $notification = PaymentNotification::first();

    expect($notification)->not->toBeNull();
    expect($notification->bank_code)->toBe('bdv');
    expect($notification->parse_status)->toBe('pending');
    expect($notification->dedup_hash)->toHaveLength(64);
});

it('creates notification with correct BDV format', function () {
    Artisan::call(SimulatePaymentNotification::class, [
        '--bank' => 'bdv',
        '--amount' => '3000',
        '--reference' => '006236568762',
        '--phone' => '04141234567',
    ]);

    $notification = PaymentNotification::first();
    $expectedHash = PaymentNotification::computeDedupHash('bdv', $notification->raw_text, 'sms');

    expect($notification->dedup_hash)->toBe($expectedHash);
    expect($notification->raw_text)->toContain('PagomovilBDV');
    expect($notification->raw_text)->toContain('006236568762');
    expect($notification->raw_text)->toContain('3000');
    expect($notification->raw_text)->toContain('04141234567');
});

it('creates notification with correct BNC format', function () {
    Artisan::call(SimulatePaymentNotification::class, [
        '--bank' => 'bnc',
        '--amount' => '2500',
        '--reference' => '12345678',
    ]);

    $notification = PaymentNotification::first();

    expect($notification->raw_text)->toContain('BNC Pago Movil Recibido');
    expect($notification->raw_text)->toContain('Ref:12345678');
});

it('creates dedup_hash collision for duplicate notifications', function () {
    Artisan::call(SimulatePaymentNotification::class, [
        '--bank' => 'bdv',
        '--amount' => '3000',
        '--reference' => '006236568762',
    ]);

    $notification = PaymentNotification::first();
    $hash1 = $notification->dedup_hash;

    $hash2 = PaymentNotification::computeDedupHash('bdv', $notification->raw_text, 'sms');

    expect($hash1)->toBe($hash2);
    expect($hash1)->toHaveLength(64);
});

it('rejects invalid bank code', function () {
    $exitCode = Artisan::call(SimulatePaymentNotification::class, [
        '--bank' => 'invalid',
        '--amount' => '3000',
        '--reference' => '006236568762',
    ]);

    expect($exitCode)->toBe(1);
    expect(PaymentNotification::count())->toBe(0);
});

// --- Model Tests ---

it('markParsed sets parsed_at and parsed_data', function () {
    $notification = PaymentNotification::forceCreate([
        'bank_code' => 'bdv',
        'raw_text' => 'test',
        'dedup_hash' => hash('sha256', 'bdv|test'),
        'parse_status' => 'pending',
    ]);

    $parsedPayment = new ParsedPayment(
        amountCents: 300000,
        reference: '006236568762',
        senderPhoneLast4: '4567',
        parsedAt: Carbon::now(),
    );

    $notification->markParsed($parsedPayment);

    expect($notification->fresh()->parse_status)->toBe('parsed');
    expect($notification->fresh()->parsed_at)->not->toBeNull();
    expect($notification->fresh()->parsed_data)->toBeArray();
    expect($notification->fresh()->parsed_data['amount_cents'])->toBe(300000);
    expect($notification->fresh()->parsed_data['reference'])->toBe('006236568762');
});

it('markParsed stores raw_groups when present', function () {
    $notification = PaymentNotification::forceCreate([
        'bank_code' => 'bdv',
        'raw_text' => 'test',
        'dedup_hash' => hash('sha256', 'bdv|test'),
        'parse_status' => 'pending',
    ]);

    $parsedPayment = new ParsedPayment(
        amountCents: 300000,
        reference: '006236568762',
        senderPhoneLast4: '4567',
        parsedAt: Carbon::now(),
        rawGroups: ['amount' => '3.000,00', 'phone' => '0424-3153557', 'reference' => '006236568762'],
    );

    $notification->markParsed($parsedPayment);

    expect($notification->fresh()->parsed_data['raw_groups'])->toBeArray();
    expect($notification->fresh()->parsed_data['raw_groups']['amount'])->toBe('3.000,00');
});

it('markFailed sets parse_error', function () {
    $notification = PaymentNotification::forceCreate([
        'bank_code' => 'bdv',
        'raw_text' => 'bad text',
        'dedup_hash' => hash('sha256', 'bdv|bad text'),
        'parse_status' => 'pending',
    ]);

    $notification->markFailed('Regex did not match');

    expect($notification->fresh()->parse_status)->toBe('failed');
    expect($notification->fresh()->parse_error)->toBe('Regex did not match');
    expect($notification->fresh()->parsed_at)->not->toBeNull();
});

it('scopePending returns only pending notifications', function () {
    PaymentNotification::forceCreate(['bank_code' => 'bdv', 'raw_text' => 'a', 'dedup_hash' => 'h1', 'parse_status' => 'pending']);
    PaymentNotification::forceCreate(['bank_code' => 'bdv', 'raw_text' => 'b', 'dedup_hash' => 'h2', 'parse_status' => 'parsed']);
    PaymentNotification::forceCreate(['bank_code' => 'bdv', 'raw_text' => 'c', 'dedup_hash' => 'h3', 'parse_status' => 'failed']);

    $pending = PaymentNotification::pending()->get();

    expect($pending)->toHaveCount(1);
    expect($pending->first()->parse_status)->toBe('pending');
});

it('scopeFailed returns only failed notifications', function () {
    PaymentNotification::forceCreate(['bank_code' => 'bdv', 'raw_text' => 'a', 'dedup_hash' => 'h1', 'parse_status' => 'pending']);
    PaymentNotification::forceCreate(['bank_code' => 'bdv', 'raw_text' => 'b', 'dedup_hash' => 'h2', 'parse_status' => 'parsed']);
    PaymentNotification::forceCreate(['bank_code' => 'bdv', 'raw_text' => 'c', 'dedup_hash' => 'h3', 'parse_status' => 'failed']);

    $failed = PaymentNotification::failed()->get();

    expect($failed)->toHaveCount(1);
    expect($failed->first()->parse_status)->toBe('failed');
});

it('computeDedupHash is deterministic', function () {
    $hash1 = PaymentNotification::computeDedupHash('bdv', 'test raw text', 'sms');
    $hash2 = PaymentNotification::computeDedupHash('bdv', 'test raw text', 'sms');

    expect($hash1)->toBe($hash2);
    expect($hash1)->toHaveLength(64);
});

it('computeDedupHash uses normalized input — same BNC payment from different sources produces same hash', function () {
    $maskedBody = 'BNC Pago Movil Recibido Bs.10455,00 Telf.0416***9503 Dia:31/05/26-20:25 Ref:603185603 Llamar al 0500-2625000 si no realizo esta Operacion';
    $fullBody = 'BNC Pago Movil Recibido Bs.10455,00 Telf.041612349503 Dia:31/05/2026-20:25 Ref:603185603 Llamar al 0500-2625000 si no realizo esta Operacion';

    $hash1 = PaymentNotification::computeDedupHash('bnc', $maskedBody, 'sms');
    $hash2 = PaymentNotification::computeDedupHash('bnc', $fullBody, 'sms');

    expect($hash1)->toBe($hash2);
    expect($hash1)->toHaveLength(64);
});

it('computeDedupHash for BDV uses full phone without canonicalization', function () {
    $body = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40';

    $hash = PaymentNotification::computeDedupHash('bdv', $body, 'sms');
    expect($hash)->toHaveLength(64);
});

// --- Seeder Tests ---

it('rejects invalid bank code using BankCode enum validation', function () {
    $exitCode = Artisan::call(SimulatePaymentNotification::class, [
        '--bank' => 'invalid_bank',
        '--amount' => '3000',
        '--reference' => '006236568762',
    ]);

    expect($exitCode)->toBe(1);
    expect(PaymentNotification::count())->toBe(0);
});

it('accepts all BankCode::cases() as valid banks', function () {
    foreach (BankCode::cases() as $bank) {
        $exitCode = Artisan::call(SimulatePaymentNotification::class, [
            '--bank' => $bank->value,
            '--amount' => '1500',
            '--reference' => '12345678',
        ]);

        expect($exitCode)->toBe(0);
    }
});

it('seeder creates expected notification count', function () {
    $seeder = new NotificationSampleSeeder;

    $seeder->run();

    // 4 samples per bank × 2 banks = 8
    expect(PaymentNotification::count())->toBe(8);
});

it('seeder is idempotent', function () {
    $seeder = new NotificationSampleSeeder;

    $seeder->run();
    $seeder->run();

    expect(PaymentNotification::count())->toBe(8);
});

// --- Factory Tests ---

it('factory creates notification with default source_type BankApp', function () {
    $notification = PaymentNotification::factory()->create();

    expect($notification->source_type)->toBe(SourceType::BankApp);
});

it('factory withSourceType state overrides source_type', function () {
    $notification = PaymentNotification::factory()->withSourceType(SourceType::BankApp)->create();

    expect($notification->source_type)->toBe(SourceType::BankApp);
});

it('factory source_type is set with createQuietly', function () {
    $notification = PaymentNotification::factory()->createQuietly();

    expect($notification->source_type)->toBe(SourceType::BankApp);
});
