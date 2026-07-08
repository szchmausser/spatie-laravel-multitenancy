<?php

use App\Models\PagoMovilDetail;
use App\Models\Payment;
use App\Models\PaymentMatch;
use App\Services\Payment\PaymentMatchGuard;

test('bank match ok', function () {
    $payment = Payment::factory()->createQuietly();
    PagoMovilDetail::factory()->createQuietly([
        'payment_id' => $payment->id,
        'sender_bank' => 'Banco Nacional de Crédito',
        'sender_phone' => '04261234567',
    ]);

    $match = PaymentMatch::factory()->createQuietly([
        'payment_id' => null,
        'parsed_bank_code' => 'bnc',
        'parsed_sender_phone_first4' => '0426',
        'parsed_sender_phone_last4' => '4567',
    ]);

    $result = PaymentMatchGuard::validate($match, $payment);

    expect($result)->toBeNull();
});

test('bank mismatch', function () {
    $payment = Payment::factory()->createQuietly();
    PagoMovilDetail::factory()->createQuietly([
        'payment_id' => $payment->id,
        'sender_bank' => 'Banco de Venezuela',
        'sender_phone' => '04261234567',
    ]);

    $match = PaymentMatch::factory()->createQuietly([
        'payment_id' => null,
        'parsed_bank_code' => 'bnc',
        'parsed_sender_phone_first4' => '0426',
        'parsed_sender_phone_last4' => '4567',
    ]);

    $result = PaymentMatchGuard::validate($match, $payment);

    expect($result)->toBeArray();
    expect($result['field'])->toBe('sender_bank');
});

test('phone mismatch bnc canonical', function () {
    $payment = Payment::factory()->createQuietly();
    PagoMovilDetail::factory()->createQuietly([
        'payment_id' => $payment->id,
        'sender_bank' => 'Banco Nacional de Crédito',
        'sender_phone' => '04121234567',
    ]);

    $match = PaymentMatch::factory()->createQuietly([
        'payment_id' => null,
        'parsed_bank_code' => 'bnc',
        'parsed_sender_phone_first4' => '0426',
        'parsed_sender_phone_last4' => '6568',
    ]);

    $result = PaymentMatchGuard::validate($match, $payment);

    expect($result)->toBeArray();
    expect($result['field'])->toBe('sender_phone');
});

test('phone match bdv full', function () {
    $payment = Payment::factory()->createQuietly();
    PagoMovilDetail::factory()->createQuietly([
        'payment_id' => $payment->id,
        'sender_bank' => 'Banco de Venezuela',
        'sender_phone' => '04243153557',
    ]);

    $match = PaymentMatch::factory()->createQuietly([
        'payment_id' => null,
        'parsed_bank_code' => 'bdv',
        'parsed_sender_phone_first4' => '0424',
        'parsed_sender_phone_number' => '0424-3153557',
        'parsed_sender_phone_last4' => '3557',
    ]);

    $result = PaymentMatchGuard::validate($match, $payment);

    expect($result)->toBeNull();
});

test('phone mismatch bdv full', function () {
    $payment = Payment::factory()->createQuietly();
    PagoMovilDetail::factory()->createQuietly([
        'payment_id' => $payment->id,
        'sender_bank' => 'Banco de Venezuela',
        'sender_phone' => '04121234567',
    ]);

    $match = PaymentMatch::factory()->createQuietly([
        'payment_id' => null,
        'parsed_bank_code' => 'bdv',
        'parsed_sender_phone_first4' => '0424',
        'parsed_sender_phone_number' => '0424-3153557',
        'parsed_sender_phone_last4' => '3557',
    ]);

    $result = PaymentMatchGuard::validate($match, $payment);

    expect($result)->toBeArray();
    expect($result['field'])->toBe('sender_phone');
});

test('pago movil detail null', function () {
    $payment = Payment::factory()->createQuietly();

    $match = PaymentMatch::factory()->createQuietly([
        'payment_id' => null,
        'parsed_bank_code' => 'bnc',
    ]);

    $result = PaymentMatchGuard::validate($match, $payment);

    expect($result)->toBeNull();
});

test('parsed sender phone first4 null', function () {
    $payment = Payment::factory()->createQuietly();
    PagoMovilDetail::factory()->createQuietly([
        'payment_id' => $payment->id,
        'sender_bank' => 'Banco Nacional de Crédito',
        'sender_phone' => '04261234567',
    ]);

    $match = PaymentMatch::factory()->createQuietly([
        'payment_id' => null,
        'parsed_bank_code' => 'bnc',
        'parsed_sender_phone_first4' => null,
    ]);

    $result = PaymentMatchGuard::validate($match, $payment);

    expect($result)->toBeNull();
});

test('invalid bank code', function () {
    $payment = Payment::factory()->createQuietly();
    PagoMovilDetail::factory()->createQuietly([
        'payment_id' => $payment->id,
        'sender_bank' => 'Banco Nacional de Crédito',
        'sender_phone' => '04261234567',
    ]);

    $match = PaymentMatch::factory()->createQuietly([
        'payment_id' => null,
        'parsed_bank_code' => 'unknown',
    ]);

    $result = PaymentMatchGuard::validate($match, $payment);

    expect($result)->toBeNull();
});
