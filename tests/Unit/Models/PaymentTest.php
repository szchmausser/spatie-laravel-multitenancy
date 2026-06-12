<?php

use App\Enums\PaymentStatus;
use App\Models\PagoMovilDetail;
use App\Models\Payment;

test('payment model uses landlord connection', function () {
    $payment = Payment::factory()->createQuietly();

    expect($payment->getConnectionName())->toBe('landlord');
});

test('payment has correct status cast', function () {
    $payment = Payment::factory()->createQuietly(['status' => PaymentStatus::Verified]);

    expect($payment->status)->toBeInstanceOf(PaymentStatus::class);
    expect($payment->status)->toBe(PaymentStatus::Verified);
});

test('payment has relationship with order', function () {
    $payment = Payment::factory()->createQuietly();

    expect($payment->order)->not->toBeNull();
});

test('payment has relationship with verifier', function () {
    $payment = Payment::factory()->createQuietly([
        'verified_by' => null,
    ]);

    expect($payment->verifier)->toBeNull();
});

test('payment has one pago movil detail', function () {
    $payment = Payment::factory()->createQuietly();

    PagoMovilDetail::factory()->createQuietly([
        'payment_id' => $payment->id,
    ]);

    expect($payment->pagoMovilDetail)->not->toBeNull();
    expect($payment->pagoMovilDetail)->toBeInstanceOf(PagoMovilDetail::class);
});

test('payment details accessor returns pago movil detail for pago_movil method', function () {
    $payment = Payment::factory()->createQuietly([
        'payment_method' => 'pago_movil',
    ]);

    PagoMovilDetail::factory()->createQuietly([
        'payment_id' => $payment->id,
    ]);

    expect($payment->details)->not->toBeNull();
    expect($payment->details)->toBeInstanceOf(PagoMovilDetail::class);
});

test('payment details accessor returns null for unknown method', function () {
    $payment = Payment::factory()->createQuietly([
        'payment_method' => 'unknown',
    ]);

    expect($payment->details)->toBeNull();
});
