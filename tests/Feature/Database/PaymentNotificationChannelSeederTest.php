<?php

use App\Models\SystemConfig;
use Database\Seeders\PaymentNotificationChannelSeeder;

test('seeder creates per-channel regex entries from existing bank regexes', function () {
    // Ensure base regex entries exist (TestCases refreshDatabase, so DB is clean)
    SystemConfig::create([
        'group' => 'reconciliation',
        'key' => 'regex_bdv',
        'value' => '/Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d\/-]+)\s+hora:\s+(?<time>[\d:]+)/i',
        'type' => 'string',
    ]);
    SystemConfig::create([
        'group' => 'reconciliation',
        'key' => 'regex_bnc',
        'value' => '/BNC\s+Pago\s+Movil\s+Recibido\s+Bs\.(?<amount>[\d.,]+)\s+Telf\.(?<phone>[\d*]+)\s+Dia:(?<date>[\d\/]+)-(?<time>[\d:]+)\s+Ref:(?<reference>\d+)/i',
        'type' => 'string',
    ]);

    (new PaymentNotificationChannelSeeder)->run();

    // Assert all 4 per-channel entries exist
    expect(SystemConfig::where('key', 'regex_bdv_sms')->exists())->toBeTrue();
    expect(SystemConfig::where('key', 'regex_bdv_bank-app')->exists())->toBeTrue();
    expect(SystemConfig::where('key', 'regex_bnc_sms')->exists())->toBeTrue();
    expect(SystemConfig::where('key', 'regex_bnc_bank-app')->exists())->toBeTrue();

    // Assert values match the base regex
    expect(SystemConfig::get('regex_bdv_sms'))->toBe(SystemConfig::get('regex_bdv'));
    expect(SystemConfig::get('regex_bdv_bank-app'))->toBe(SystemConfig::get('regex_bdv'));
    expect(SystemConfig::get('regex_bnc_sms'))->toBe(SystemConfig::get('regex_bnc'));
    expect(SystemConfig::get('regex_bnc_bank-app'))->toBe(SystemConfig::get('regex_bnc'));

    // Assert base keys still exist (not deleted — backward compat)
    expect(SystemConfig::where('key', 'regex_bdv')->exists())->toBeTrue();
    expect(SystemConfig::where('key', 'regex_bnc')->exists())->toBeTrue();
});

test('seeder is idempotent and does not create duplicates', function () {
    SystemConfig::create([
        'group' => 'reconciliation',
        'key' => 'regex_bdv',
        'value' => '/Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d\/-]+)\s+hora:\s+(?<time>[\d:]+)/i',
        'type' => 'string',
    ]);

    (new PaymentNotificationChannelSeeder)->run();
    $countAfterFirstRun = SystemConfig::where('key', 'regex_bdv_sms')->count();

    (new PaymentNotificationChannelSeeder)->run();
    $countAfterSecondRun = SystemConfig::where('key', 'regex_bdv_sms')->count();

    expect($countAfterFirstRun)->toBe(1);
    expect($countAfterSecondRun)->toBe(1);
});

test('seeder skips banks without base regex', function () {
    // Only create regex_bdv, no regex_bnc
    SystemConfig::create([
        'group' => 'reconciliation',
        'key' => 'regex_bdv',
        'value' => '/Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d\/-]+)\s+hora:\s+(?<time>[\d:]+)/i',
        'type' => 'string',
    ]);

    (new PaymentNotificationChannelSeeder)->run();

    // BDV entries should exist
    expect(SystemConfig::where('key', 'regex_bdv_sms')->exists())->toBeTrue();
    expect(SystemConfig::where('key', 'regex_bdv_bank-app')->exists())->toBeTrue();

    // BNC entries should NOT exist (no base regex)
    expect(SystemConfig::where('key', 'regex_bnc_sms')->exists())->toBeFalse();
    expect(SystemConfig::where('key', 'regex_bnc_bank-app')->exists())->toBeFalse();
});

test('seeded regex keys are consistent with SourceType enum values', function () {
    SystemConfig::create([
        'group' => 'reconciliation',
        'key' => 'regex_bdv',
        'value' => '/Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d\/-]+)\s+hora:\s+(?<time>[\d:]+)/i',
        'type' => 'string',
    ]);
    SystemConfig::create([
        'group' => 'reconciliation',
        'key' => 'regex_bnc',
        'value' => '/BNC\s+Pago\s+Movil\s+Recibido\s+Bs\.(?<amount>[\d.,]+)\s+Telf\.(?<phone>[\d*]+)\s+Dia:(?<date>[\d\/]+)-(?<time>[\d:]+)\s+Ref:(?<reference>\d+)/i',
        'type' => 'string',
    ]);

    (new PaymentNotificationChannelSeeder)->run();

    // The seeder creates entries for 'sms' and 'bank-app'.
    // These MUST match the source types that notifications can have.
    // If a new SourceType case is added, the seeder and test must both be updated.
    $expectedRegexSourceTypes = ['sms', 'bank-app'];

    foreach (['bdv', 'bnc'] as $bank) {
        foreach ($expectedRegexSourceTypes as $sourceType) {
            $key = "regex_{$bank}_{$sourceType}";
            expect(SystemConfig::where('key', $key)->exists())
                ->toBeTrue("Missing seeded regex key: {$key}");
        }
    }
});
