<?php

use App\Enums\BankCode;

it('has Bdv case with value bdv', function () {
    expect(BankCode::Bdv)->toBeInstanceOf(BankCode::class);
    expect(BankCode::Bdv->value)->toBe('bdv');
});

it('has Bnc case with value bnc', function () {
    expect(BankCode::Bnc)->toBeInstanceOf(BankCode::class);
    expect(BankCode::Bnc->value)->toBe('bnc');
});

it('returns code from value', function () {
    expect(BankCode::Bdv->code())->toBe('bdv');
    expect(BankCode::Bnc->code())->toBe('bnc');
});

it('returns display name', function () {
    expect(BankCode::Bdv->name())->toBe('Banco de Venezuela');
    expect(BankCode::Bnc->name())->toBe('Banco Nacional de Crédito');
});

it('Bnc applies canonical phone', function () {
    expect(BankCode::Bnc->appliesCanonicalPhone())->toBeTrue();
});

it('Bdv does not apply canonical phone', function () {
    expect(BankCode::Bdv->appliesCanonicalPhone())->toBeFalse();
});

it('Bdv returns single date format', function () {
    $formats = BankCode::Bdv->dateFormats();
    expect($formats)->toBeArray();
    expect($formats)->toHaveCount(1);
    expect($formats[0])->toBe('j/n/Y G:i');
});

it('Bnc returns two date formats (2-digit then 4-digit year)', function () {
    $formats = BankCode::Bnc->dateFormats();
    expect($formats)->toBeArray();
    expect($formats)->toHaveCount(2);
    expect($formats[0])->toBe('d/m/y H:i');
    expect($formats[1])->toBe('d/m/Y H:i');
});

it('Bdv returns android package', function () {
    $package = BankCode::Bdv->androidPackage();
    expect($package)->toBeString();
    expect($package)->not->toBeEmpty();
});

it('Bnc returns android package', function () {
    $package = BankCode::Bnc->androidPackage();
    expect($package)->toBeString();
    expect($package)->not->toBeEmpty();
});

it('cases returns both Bdv and Bnc', function () {
    $cases = BankCode::cases();
    expect($cases)->toHaveCount(2);
    expect($cases[0])->toBe(BankCode::Bdv);
    expect($cases[1])->toBe(BankCode::Bnc);
});

it('Bdv toArray returns all metadata', function () {
    $data = BankCode::Bdv->toArray();
    expect($data)->toHaveKeys(['code', 'name', 'applies_canonical_phone', 'date_formats', 'android_package']);
    expect($data['code'])->toBe('bdv');
    expect($data['applies_canonical_phone'])->toBeFalse();
});

it('Bnc toArray returns all metadata', function () {
    $data = BankCode::Bnc->toArray();
    expect($data)->toHaveKeys(['code', 'name', 'applies_canonical_phone', 'date_formats', 'android_package']);
    expect($data['code'])->toBe('bnc');
    expect($data['applies_canonical_phone'])->toBeTrue();
});
