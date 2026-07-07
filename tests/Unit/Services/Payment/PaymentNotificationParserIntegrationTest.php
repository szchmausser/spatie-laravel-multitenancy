<?php

use App\Models\PaymentNotification;
use App\Models\SystemConfig;
use App\Services\Payment\PaymentNotificationParser;

beforeEach(function () {
    $this->parser = new PaymentNotificationParser;

    // Per-channel regex patterns (no fallback — sourceType is required)
    SystemConfig::create(['group' => 'reconciliation', 'key' => 'regex_bdv_sms', 'value' => '/Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d-]+)\s+hora:\s+(?<time>[\d:]+)/i', 'type' => 'string']);
    SystemConfig::create(['group' => 'reconciliation', 'key' => 'regex_bdv_bank-app', 'value' => '/Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d-]+)\s+hora:\s+(?<time>[\d:]+)/i', 'type' => 'string']);
    SystemConfig::create(['group' => 'reconciliation', 'key' => 'regex_bnc_sms', 'value' => '/BNC\s+Pago\s+Movil\s+Recibido\s+Bs\.(?<amount>[\d.,]+)\s+Telf\.(?<phone>[\d*]+)\s+Dia:(?<date>[\d\/]+)-(?<time>[\d:]+)\s+Ref:(?<reference>\d+)/i', 'type' => 'string']);
    SystemConfig::create(['group' => 'reconciliation', 'key' => 'regex_bnc_bank-app', 'value' => '/BNC\s+Pago\s+Movil\s+Recibido\s+Bs\.(?<amount>[\d.,]+)\s+Telf\.(?<phone>[\d*]+)\s+Dia:(?<date>[\d\/]+)-(?<time>[\d:]+)\s+Ref:(?<reference>\d+)/i', 'type' => 'string']);
});

// --- BDV Integration ---

it('parses real BDV notification with comma decimals', function () {
    $rawText = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40';
    $parsed = $this->parser->parse('bdv', $rawText, 'sms');

    expect($parsed)->not->toBeNull();
    expect($parsed->amountCents)->toBe(300000);
    expect($parsed->reference)->toBe('006236568762');
    expect($parsed->senderPhoneLast4)->toBe('3557');
});

it('parses real BDV notification with large amount', function () {
    $rawText = 'Recibiste un PagomovilBDV por Bs. 15.750,50 del 0412-9876543 Ref: 009988776655 en fecha: 15-06-26 hora: 14:30';
    $parsed = $this->parser->parse('bdv', $rawText, 'sms');

    expect($parsed)->not->toBeNull();
    expect($parsed->amountCents)->toBe(1575050);
    expect($parsed->reference)->toBe('009988776655');
});

// --- BNC Integration ---

it('parses real BNC notification', function () {
    $rawText = 'BNC Pago Movil Recibido Bs.10455,00 Telf.0416***9503 Dia:31/05/26-20:25 Ref:603185603 Llamar al 0500-2625000 si no realizo esta Operacion';
    $parsed = $this->parser->parse('bnc', $rawText, 'sms');

    expect($parsed)->not->toBeNull();
    expect($parsed->amountCents)->toBe(1045500);
    expect($parsed->reference)->toBe('603185603');
    expect($parsed->senderPhoneLast4)->toBe('9503');
});

it('parses real BNC notification with small amount', function () {
    $rawText = 'BNC Pago Movil Recibido Bs.250,75 Telf.0412***1234 Dia:15/06/26-10:00 Ref:998877665 Llamar al 0500-2625000 si no realizo esta Operacion';
    $parsed = $this->parser->parse('bnc', $rawText, 'sms');

    expect($parsed)->not->toBeNull();
    expect($parsed->amountCents)->toBe(25075);
    expect($parsed->reference)->toBe('998877665');
});

// --- End-to-End: Simulator → Parser Pipeline ---

it('simulated BDV notification round-trips through parser correctly', function () {
    $now = now();
    $rawText = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: '.$now->format('d-m-y').' hora: '.$now->format('H:i');
    $dedupHash = PaymentNotification::computeDedupHash('bdv', $rawText, 'sms');

    $notification = PaymentNotification::forceCreate([
        'bank_code' => 'bdv',
        'raw_text' => $rawText,
        'dedup_hash' => $dedupHash,
        'parse_status' => 'pending',
    ]);

    $parsed = $this->parser->parse($notification->bank_code, $notification->raw_text, 'sms');
    $notification->markParsed($parsed);

    expect($notification->fresh()->parse_status)->toBe('parsed');
    expect($notification->fresh()->parsed_data['amount_cents'])->toBe(300000);
    expect($notification->fresh()->parsed_data['reference'])->toBe('006236568762');
});

it('simulated BNC notification round-trips through parser correctly', function () {
    $now = now();
    $rawText = 'BNC Pago Movil Recibido Bs.2500,50 Telf.0412***6789 Dia:'.$now->format('d/m/y').'-'.$now->format('H:i').' Ref:12345678 Llamar al 0500-2625000 si no realizo esta Operacion';
    $dedupHash = PaymentNotification::computeDedupHash('bnc', $rawText, 'sms');

    $notification = PaymentNotification::forceCreate([
        'bank_code' => 'bnc',
        'raw_text' => $rawText,
        'dedup_hash' => $dedupHash,
        'parse_status' => 'pending',
    ]);

    $parsed = $this->parser->parse($notification->bank_code, $notification->raw_text, 'sms');
    $notification->markParsed($parsed);

    expect($notification->fresh()->parsed_data['amount_cents'])->toBe(250050);
    expect($notification->fresh()->parsed_data['reference'])->toBe('12345678');
});

// --- Channel-aware Integration Tests ---

describe('channel-aware integration', function () {

    it('parses BDV notification with sourceType sms', function () {
        $text = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40';
        $parsed = $this->parser->parse('bdv', $text, 'sms');

        expect($parsed)->not->toBeNull();
        expect($parsed->amountCents)->toBe(300000);
        expect($parsed->reference)->toBe('006236568762');
    });

    it('parses BDV notification with sourceType bank-app', function () {
        $text = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40';
        $parsed = $this->parser->parse('bdv', $text, 'bank-app');

        expect($parsed)->not->toBeNull();
        expect($parsed->amountCents)->toBe(300000);
        expect($parsed->reference)->toBe('006236568762');
    });

    it('returns null when channel regex key is missing', function () {
        $text = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40';
        $parsed = $this->parser->parse('bdv', $text, 'missing_channel');

        expect($parsed)->toBeNull();
    });

    it('normalizeForDedup with sourceType', function () {
        $text = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40';
        $result = $this->parser->normalizeForDedup('bdv', $text, 'bank-app');

        expect($result)->toBe('300000|04243153557|02-06-26 09:40|006236568762');
    });

    it('returns null when passing sourceType without matching regex key', function () {
        $text = 'BNC Pago Movil Recibido Bs.10455,00 Telf.0416***9503 Dia:31/05/26-20:25 Ref:603185603 Llamar al 0500-2625000 si no realizo esta Operacion';
        $parsed = $this->parser->parse('bnc', $text, 'missing_channel');

        expect($parsed)->toBeNull();
    });
});
