<?php

use App\Models\SystemConfig;
use Database\Seeders\SystemConfigSeeder;

test('system config seeder creates per-channel regex entries directly', function () {
    (new SystemConfigSeeder)->run();

    // Assert all 4 per-channel entries exist
    expect(SystemConfig::where('key', 'regex_bdv_sms')->exists())->toBeTrue();
    expect(SystemConfig::where('key', 'regex_bdv_bank-app')->exists())->toBeTrue();
    expect(SystemConfig::where('key', 'regex_bnc_sms')->exists())->toBeTrue();
    expect(SystemConfig::where('key', 'regex_bnc_bank-app')->exists())->toBeTrue();

    // Assert no legacy fallback keys exist
    expect(SystemConfig::where('key', 'regex_bdv')->exists())->toBeFalse();
    expect(SystemConfig::where('key', 'regex_bnc')->exists())->toBeFalse();
});

test('seeder is idempotent and does not create duplicates', function () {
    (new SystemConfigSeeder)->run();
    $countAfterFirstRun = SystemConfig::where('key', 'regex_bdv_sms')->count();

    (new SystemConfigSeeder)->run();
    $countAfterSecondRun = SystemConfig::where('key', 'regex_bdv_sms')->count();

    expect($countAfterFirstRun)->toBe(1);
    expect($countAfterSecondRun)->toBe(1);
});

test('seeded regex keys are consistent with SourceType enum values', function () {
    (new SystemConfigSeeder)->run();

    $expectedRegexSourceTypes = ['sms', 'bank-app'];

    foreach (['bdv', 'bnc'] as $bank) {
        foreach ($expectedRegexSourceTypes as $sourceType) {
            $key = "regex_{$bank}_{$sourceType}";
            expect(SystemConfig::where('key', $key)->exists())
                ->toBeTrue("Missing seeded regex key: {$key}");
        }
    }
});
