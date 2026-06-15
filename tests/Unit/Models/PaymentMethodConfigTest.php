<?php

use App\Models\PaymentMethodConfig;

test('payment method config uses landlord connection', function () {
    $config = PaymentMethodConfig::factory()->createQuietly();

    expect($config->getConnectionName())->toBe('landlord');
});

test('payment method config has timestamps', function () {
    $config = PaymentMethodConfig::factory()->createQuietly();

    expect($config->timestamps)->toBeTrue();
    expect($config->created_at)->not->toBeNull();
    expect($config->updated_at)->not->toBeNull();
});

test('payment method config fillable fields', function () {
    $config = PaymentMethodConfig::factory()->createQuietly([
        'type' => 'bank_transfer',
        'label' => 'Mercantil',
        'bank_name' => 'Banco Mercantil',
        'account_number' => '0134-0000-00000000',
        'account_holder' => 'Mi Empresa C.A.',
        'holder_id' => 'J-12345678-9',
        'is_active' => true,
        'sort_order' => 1,
        'metadata' => ['notes' => 'Cuenta principal'],
    ]);

    expect($config->type)->toBe('bank_transfer');
    expect($config->label)->toBe('Mercantil');
    expect($config->bank_name)->toBe('Banco Mercantil');
    expect($config->account_number)->toBe('0134-0000-00000000');
    expect($config->account_holder)->toBe('Mi Empresa C.A.');
    expect($config->holder_id)->toBe('J-12345678-9');
    expect($config->is_active)->toBeTrue();
    expect($config->sort_order)->toBe(1);
    expect($config->metadata)->toBe(['notes' => 'Cuenta principal']);
});

test('payment method config scope active filters correctly', function () {
    PaymentMethodConfig::factory()->createQuietly(['is_active' => true]);
    PaymentMethodConfig::factory()->createQuietly(['is_active' => false]);

    $active = PaymentMethodConfig::active()->get();

    expect($active)->toHaveCount(1);
    expect($active->first()->is_active)->toBeTrue();
});

test('payment method config scope of type filters correctly', function () {
    PaymentMethodConfig::factory()->createQuietly(['type' => 'pago_movil']);
    PaymentMethodConfig::factory()->createQuietly(['type' => 'bank_transfer']);
    PaymentMethodConfig::factory()->createQuietly(['type' => 'bank_transfer']);

    $bankTransfers = PaymentMethodConfig::ofType('bank_transfer')->get();

    expect($bankTransfers)->toHaveCount(2);
});

test('payment method config default values via factory', function () {
    $config = PaymentMethodConfig::factory()->createQuietly();

    expect($config->is_active)->toBeTrue();
    expect($config->sort_order)->toBe(0);
});
