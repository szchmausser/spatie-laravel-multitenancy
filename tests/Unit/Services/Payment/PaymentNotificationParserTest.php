<?php

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

// --- BDV Tests ---

test('parses BDV notification correctly', function () {
    $text = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40';
    $result = $this->parser->parse('bdv', $text, 'sms');

    expect($result)->not->toBeNull();
    expect($result->amountCents)->toBe(300000);
    expect($result->reference)->toBe('006236568762');
    expect($result->senderPhoneLast4)->toBe('3557');
});

test('parses BDV with different amount', function () {
    $text = 'Recibiste un PagomovilBDV por Bs. 15.750,50 del 0412-9876543 Ref: 009988776655 en fecha: 15-06-26 hora: 14:30';
    $result = $this->parser->parse('bdv', $text, 'sms');

    expect($result->amountCents)->toBe(1575050);
    expect($result->reference)->toBe('009988776655');
    expect($result->senderPhoneLast4)->toBe('6543');
});

test('parses BDV with no decimals', function () {
    $text = 'Recibiste un PagomovilBDV por Bs. 500 del 0424-1111222 Ref: 1234567890 en fecha: 01-01-26 hora: 00:00';
    $result = $this->parser->parse('bdv', $text, 'sms');

    expect($result->amountCents)->toBe(50000);
});

// --- BNC Tests ---

test('parses BNC notification correctly', function () {
    $text = 'BNC Pago Movil Recibido Bs.10455,00 Telf.0416***9503 Dia:31/05/26-20:25 Ref:603185603 Llamar al 0500-2625000 si no realizo esta Operacion';
    $result = $this->parser->parse('bnc', $text, 'sms');

    expect($result)->not->toBeNull();
    expect($result->amountCents)->toBe(1045500);
    expect($result->reference)->toBe('603185603');
    expect($result->senderPhoneLast4)->toBe('9503');
});

test('parses BNC with different amount', function () {
    $text = 'BNC Pago Movil Recibido Bs.250,75 Telf.0412***1234 Dia:15/06/26-10:00 Ref:998877665 Llamar al 0500-2625000 si no realizo esta Operacion';
    $result = $this->parser->parse('bnc', $text, 'sms');

    expect($result->amountCents)->toBe(25075);
    expect($result->reference)->toBe('998877665');
    expect($result->senderPhoneLast4)->toBe('1234');
});

// --- normalizeAmount Tests ---

test('normalizeAmount handles comma decimal', function () {
    expect($this->parser->normalizeAmount('1234,56'))->toBe(123456);
});

test('normalizeAmount handles dot as thousands sep', function () {
    // In Venezuelan format, dot is thousands separator
    expect($this->parser->normalizeAmount('1234.56'))->toBe(12345600);
});

test('normalizeAmount handles no decimal', function () {
    expect($this->parser->normalizeAmount('1234'))->toBe(123400);
});

test('normalizeAmount handles thousands separator with comma', function () {
    expect($this->parser->normalizeAmount('1.234,56'))->toBe(123456);
});

test('normalizeAmount handles plain comma decimal', function () {
    expect($this->parser->normalizeAmount('1234,56'))->toBe(123456);
});

test('normalizeAmount handles zero', function () {
    expect($this->parser->normalizeAmount('0'))->toBe(0);
});

test('normalizeAmount handles whitespace', function () {
    expect($this->parser->normalizeAmount('  1234,56  '))->toBe(123456);
});

// --- normalizeRef Tests ---

test('normalizeRef trims whitespace', function () {
    expect(normalizeRef('  123456  '))->toBe('123456');
});

test('normalizeRef uppercases', function () {
    expect(normalizeRef('abc123'))->toBe('ABC123');
});

test('normalizeRef handles mixed case with spaces', function () {
    expect(normalizeRef('  AbC123  '))->toBe('ABC123');
});

test('normalizeRef handles empty string', function () {
    expect(normalizeRef(''))->toBe('');
});

test('normalizeRef handles numeric string', function () {
    expect(normalizeRef('123456'))->toBe('123456');
});

// --- extractLast4 Tests ---

test('extractLast4 handles normal phone', function () {
    expect($this->parser->extractLast4('04243153557'))->toBe('3557');
});

test('extractLast4 handles masked phone', function () {
    expect($this->parser->extractLast4('0416***9503'))->toBe('9503');
});

test('extractLast4 handles null', function () {
    expect($this->parser->extractLast4(null))->toBeNull();
});

test('extractLast4 handles short string', function () {
    expect($this->parser->extractLast4('12'))->toBe('12');
});

// --- canonicalPhone Tests ---

test('canonicalPhone returns first4+last4 for full digits', function () {
    expect($this->parser->canonicalPhone('04121234567'))->toBe('04124567');
});

test('canonicalPhone returns first4+last4 for masked phone', function () {
    expect($this->parser->canonicalPhone('0416***9503'))->toBe('04169503');
});

test('canonicalPhone returns empty string for no digits', function () {
    expect($this->parser->canonicalPhone('***-***'))->toBe('');
});

test('canonicalPhone returns empty string for empty input', function () {
    expect($this->parser->canonicalPhone(''))->toBe('');
});

// --- parseDateMultiFormat Tests ---

test('parseDateMultiFormat parses BDV format j/n/Y G:i', function () {
    $result = $this->parser->parseDateMultiFormat('15/1/2026', '09:40', ['j/n/Y G:i']);
    expect($result)->toBe('2026-01-15T09:40:00');
});

test('parseDateMultiFormat parses BNC 2-digit year format d/m/y', function () {
    $result = $this->parser->parseDateMultiFormat('15/01/26', '09:40', ['d/m/y H:i', 'd/m/Y H:i']);
    expect($result)->toBe('2026-01-15T09:40:00');
});

test('parseDateMultiFormat parses BNC 4-digit year format d/m/Y', function () {
    $result = $this->parser->parseDateMultiFormat('15/01/2026', '09:40', ['d/m/y H:i', 'd/m/Y H:i']);
    expect($result)->toBe('2026-01-15T09:40:00');
});

test('parseDateMultiFormat falls back to raw string for unparseable', function () {
    $result = $this->parser->parseDateMultiFormat('not-a-date', '00:00', ['d/m/y H:i']);
    expect($result)->toBe('not-a-date 00:00');
});

test('parseDateMultiFormat does not throw on invalid input', function () {
    $result = $this->parser->parseDateMultiFormat('', '', ['d/m/y H:i']);
    expect($result)->toBe(' ');
});

// --- normalizeForDedup Tests ---

test('normalizeForDedup returns amount|phone|date|ref for BDV', function () {
    $text = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40';
    $result = $this->parser->normalizeForDedup('bdv', $text, 'sms');

    expect(substr_count($result, '|'))->toBe(3);
    // Amount is cents: 300000, phone stripped of non-digits: 04243153557, date raw fallback: 02-06-26 09:40, ref: 006236568762
    expect($result)->toBe('300000|04243153557|02-06-26 09:40|006236568762');
});

test('normalizeForDedup same BNC masked and full phone produce same string', function () {
    $maskedText = 'BNC Pago Movil Recibido Bs.10455,00 Telf.0416***9503 Dia:31/05/26-20:25 Ref:603185603 Llamar al 0500-2625000 si no realizo esta Operacion';
    // Full phone with same last 4 digits (9503) as the masked version
    $fullText = 'BNC Pago Movil Recibido Bs.10455,00 Telf.041612349503 Dia:31/05/2026-20:25 Ref:603185603 Llamar al 0500-2625000 si no realizo esta Operacion';

    $result1 = $this->parser->normalizeForDedup('bnc', $maskedText, 'sms');
    $result2 = $this->parser->normalizeForDedup('bnc', $fullText, 'sms');

    expect($result1)->toBe($result2);
    // canonicalPhone(0416***9503) = 0416 + 9503 = 04169503
    expect($result1)->toContain('|04169503|');
});

test('normalizeForDedup falls back to raw body when regex does not match', function () {
    $text = 'BNC Pago Movil Recibido Bs.10455,00 Telf. Dia:31/05/26-20:25 Ref:603185603 Llamar al 0500-2625000 si no realizo esta Operacion';
    $result = $this->parser->normalizeForDedup('bnc', $text, 'sms');

    // No phone group match → regex fails → fallback to raw body
    expect($result)->toBe($text);
});

// --- Error Cases ---

test('returns null for unknown bank', function () {
    $result = $this->parser->parse('unknown', 'some text', 'sms');
    expect($result)->toBeNull();
});

test('returns null for non-matching text', function () {
    $result = $this->parser->parse('bdv', 'invalid text that does not match', 'sms');
    expect($result)->toBeNull();
});

test('returns regex pattern for valid bank', function () {
    $regex = SystemConfig::get('regex_bdv_sms');
    expect($regex)->not->toBeEmpty();
});

// --- Channel-aware (sourceType) Tests ---

describe('channel-aware parsing', function () {

    test('parses with sourceType sms', function () {
        $text = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40';
        $result = $this->parser->parse('bdv', $text, 'sms');

        expect($result)->not->toBeNull();
        expect($result->amountCents)->toBe(300000);
        expect($result->reference)->toBe('006236568762');
    });

    test('parses with sourceType bank-app', function () {
        $text = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40';
        $result = $this->parser->parse('bdv', $text, 'bank-app');

        expect($result)->not->toBeNull();
        expect($result->amountCents)->toBe(300000);
        expect($result->reference)->toBe('006236568762');
    });

    test('returns null when sourceType key does not exist', function () {
        $text = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40';
        $result = $this->parser->parse('bdv', $text, 'nonexistent_channel');

        expect($result)->toBeNull();
    });

    test('normalizeForDedup with sourceType uses channel regex', function () {
        $text = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40';
        $result = $this->parser->normalizeForDedup('bdv', $text, 'bank-app');

        expect(substr_count($result, '|'))->toBe(3);
        expect($result)->toBe('300000|04243153557|02-06-26 09:40|006236568762');
    });

    test('normalizeForDedup falls back to raw body when sourceType key not found', function () {
        $text = 'BNC Pago Movil Recibido Bs.10455,00 Telf.0416***9503 Dia:31/05/26-20:25 Ref:603185603 Llamar al 0500-2625000 si no realizo esta Operacion';
        $result = $this->parser->normalizeForDedup('bnc', $text, 'nonexistent_channel');

        expect($result)->toBe($text);
    });

    test('returns null when sourceType regex exists but does not match text', function () {
        $text = 'This text does not match the BDV push pattern at all';
        $result = $this->parser->parse('bdv', $text, 'bank-app');

        expect($result)->toBeNull();
    });

    test('normalizeForDedup returns raw body when sourceType regex exists but does not match', function () {
        $text = 'Non-matching text for BDV push';
        $result = $this->parser->normalizeForDedup('bdv', $text, 'bank-app');

        expect($result)->toBe($text);
    });
});
