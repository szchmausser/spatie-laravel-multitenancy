<?php

use App\Models\PagoMovilDetail;

test('pago movil detail uses landlord connection', function () {
    $detail = PagoMovilDetail::factory()->createQuietly();

    expect($detail->getConnectionName())->toBe('landlord');
});

test('pago movil detail has payment_id as primary key', function () {
    $detail = PagoMovilDetail::factory()->createQuietly();

    expect($detail->getKeyName())->toBe('payment_id');
    expect($detail->getIncrementing())->toBeFalse();
});

test('pago movil detail has relationship with payment', function () {
    $detail = PagoMovilDetail::factory()->createQuietly();

    expect($detail->payment)->not->toBeNull();
});
