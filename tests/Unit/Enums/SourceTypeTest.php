<?php

use App\Enums\SourceType;

test('source type enum has correct case values', function () {
    expect(SourceType::AndroidPush->value)->toBe('android_push');
});

test('source type enum label returns human-readable string', function () {
    expect(SourceType::AndroidPush->label())->toBe('Android Push');
});

test('source type enum values returns all case values', function () {
    expect(SourceType::values())->toBe(['android_push']);
});

test('source type enum tryFrom returns null for unknown value', function () {
    expect(SourceType::tryFrom('sms'))->toBeNull();
});
