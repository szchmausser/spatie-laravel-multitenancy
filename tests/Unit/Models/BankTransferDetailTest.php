<?php

use App\Models\BankTransferDetail;

test('bank transfer detail uses landlord connection', function () {
    $detail = BankTransferDetail::factory()->createQuietly();

    expect($detail->getConnectionName())->toBe('landlord');
});

test('bank transfer detail has payment_id as primary key', function () {
    $detail = BankTransferDetail::factory()->createQuietly();

    expect($detail->getKeyName())->toBe('payment_id');
    expect($detail->getIncrementing())->toBeFalse();
});

test('bank transfer detail has no timestamps', function () {
    $detail = BankTransferDetail::factory()->createQuietly();

    expect($detail->timestamps)->toBeFalse();
});

test('bank transfer detail belongs to payment', function () {
    $detail = BankTransferDetail::factory()->createQuietly();

    expect($detail->payment)->not->toBeNull();
    expect($detail->payment->id)->toBe($detail->payment_id);
});

test('bank transfer detail fillable fields', function () {
    $detail = BankTransferDetail::factory()->createQuietly([
        'account_number' => '0134-0000-00000000',
        'bank_name' => 'Banco Mercantil',
        'account_holder' => 'Mi Empresa C.A.',
        'holder_id' => 'J-12345678-9',
    ]);

    expect($detail->account_number)->toBe('0134-0000-00000000');
    expect($detail->bank_name)->toBe('Banco Mercantil');
    expect($detail->account_holder)->toBe('Mi Empresa C.A.');
    expect($detail->holder_id)->toBe('J-12345678-9');
});
