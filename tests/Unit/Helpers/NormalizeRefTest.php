<?php

test('normalizeRef trims leading and trailing whitespace', function () {
    expect(normalizeRef('  123456  '))->toBe('123456');
});

test('normalizeRef converts to uppercase', function () {
    expect(normalizeRef('abc123'))->toBe('ABC123');
});

test('normalizeRef handles mixed case', function () {
    expect(normalizeRef('AbCdEf'))->toBe('ABCDEF');
});

test('normalizeRef handles empty string', function () {
    expect(normalizeRef(''))->toBe('');
});

test('normalizeRef handles numeric string', function () {
    expect(normalizeRef('123456'))->toBe('123456');
});

test('normalizeRef handles already uppercase', function () {
    expect(normalizeRef('ABC123'))->toBe('ABC123');
});

test('normalizeRef handles string with only spaces', function () {
    expect(normalizeRef('   '))->toBe('');
});

test('normalizeRef handles single character', function () {
    expect(normalizeRef('a'))->toBe('A');
});

test('normalizeRef handles reference with special characters', function () {
    expect(normalizeRef('REF-123'))->toBe('REF-123');
});

test('normalizeRef is idempotent', function () {
    $ref = '  ABC123  ';
    expect(normalizeRef(normalizeRef($ref)))->toBe(normalizeRef($ref));
});
