<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Payment Gateway
    |--------------------------------------------------------------------------
    */
    'default' => env('PAYMENT_GATEWAY', 'pago_movil'),

    /*
    |--------------------------------------------------------------------------
    | Order Expiry (hours)
    |--------------------------------------------------------------------------
    */
    'order_expiry_hours' => env('ORDER_EXPIRY_HOURS', 48),

    /*
    |--------------------------------------------------------------------------
    | Pago Móvil — Business Receiving Account
    |--------------------------------------------------------------------------
    | The phone, bank, and RIF that tenants send money TO.
    */
    'pago_movil' => [
        'phone' => env('PAGO_MOVIL_PHONE', '04141234567'),
        'bank' => env('PAGO_MOVIL_BANK', 'Banco de Venezuela'),
        'rif' => env('PAGO_MOVIL_RIF', 'J-12345678-9'),
    ],
];
