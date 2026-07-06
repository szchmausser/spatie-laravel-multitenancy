<?php

use App\Enums\SourceType;

test('source type enum has correct case values', function () {
    expect(SourceType::BankApp->value)->toBe('bank-app');
});

test('source type enum labels', function () {
    expect(SourceType::BankApp->label())->toBe('Bank App');
    expect(SourceType::Sms->label())->toBe('SMS');
});

test('source type enum values returns all case values', function () {
    expect(SourceType::values())->toBe(['bank-app', 'sms']);
});

test('source type enum tryFrom returns null for unknown value', function () {
    expect(SourceType::tryFrom('unknown'))->toBeNull();
});
