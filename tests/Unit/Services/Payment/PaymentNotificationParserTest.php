<?php

use App\Models\SystemConfig;
use App\Services\Payment\PaymentNotificationParser;

beforeEach(function () {
    $this->parser = new PaymentNotificationParser;

    // Real regex patterns from plan section 2.1 (with /i delimiters)
    SystemConfig::create(['group' => 'reconciliation', 'key' => 'regex_bdv', 'value' => '/Recibiste\s+un\s+PagomovilBDV\s+por\s+Bs\.\s+(?<amount>[\d.,]+)\s+del\s+(?<phone>[\d-]+)\s+Ref:\s+(?<reference>\d+)\s+en\s+fecha:\s+(?<date>[\d-]+)\s+hora:\s+(?<time>[\d:]+)/i', 'type' => 'string']);
    SystemConfig::create(['group' => 'reconciliation', 'key' => 'regex_bnc', 'value' => '/BNC\s+Pago\s+Movil\s+Recibido\s+Bs\.(?<amount>[\d.,]+)\s+Telf\.(?<phone>[\d*]+)\s+Dia:(?<date>[\d\/]+)-(?<time>[\d:]+)\s+Ref:(?<reference>\d+)/i', 'type' => 'string']);
});

// --- BDV Tests ---

test('parses BDV notification correctly', function () {
    $text = 'Recibiste un PagomovilBDV por Bs. 3.000,00 del 0424-3153557 Ref: 006236568762 en fecha: 02-06-26 hora: 09:40';
    $result = $this->parser->parse('bdv', $text);

    expect($result)->not->toBeNull();
    expect($result->amountCents)->toBe(300000);
    expect($result->reference)->toBe('006236568762');
    expect($result->senderPhoneLast4)->toBe('3557');
});

test('parses BDV with different amount', function () {
    $text = 'Recibiste un PagomovilBDV por Bs. 15.750,50 del 0412-9876543 Ref: 009988776655 en fecha: 15-06-26 hora: 14:30';
    $result = $this->parser->parse('bdv', $text);

    expect($result->amountCents)->toBe(1575050);
    expect($result->reference)->toBe('009988776655');
    expect($result->senderPhoneLast4)->toBe('6543');
});

test('parses BDV with no decimals', function () {
    $text = 'Recibiste un PagomovilBDV por Bs. 500 del 0424-1111222 Ref: 1234567890 en fecha: 01-01-26 hora: 00:00';
    $result = $this->parser->parse('bdv', $text);

    expect($result->amountCents)->toBe(50000);
});

// --- BNC Tests ---

test('parses BNC notification correctly', function () {
    $text = 'BNC Pago Movil Recibido Bs.10455,00 Telf.0416***9503 Dia:31/05/26-20:25 Ref:603185603 Llamar al 0500-2625000 si no realizo esta Operacion';
    $result = $this->parser->parse('bnc', $text);

    expect($result)->not->toBeNull();
    expect($result->amountCents)->toBe(1045500);
    expect($result->reference)->toBe('603185603');
    expect($result->senderPhoneLast4)->toBe('9503');
});

test('parses BNC with different amount', function () {
    $text = 'BNC Pago Movil Recibido Bs.250,75 Telf.0412***1234 Dia:15/06/26-10:00 Ref:998877665 Llamar al 0500-2625000 si no realizo esta Operacion';
    $result = $this->parser->parse('bnc', $text);

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

// --- Error Cases ---

test('returns null for unknown bank', function () {
    $result = $this->parser->parse('unknown', 'some text');
    expect($result)->toBeNull();
});

test('returns null for non-matching text', function () {
    $result = $this->parser->parse('bdv', 'invalid text that does not match');
    expect($result)->toBeNull();
});

test('returns regex pattern for valid bank', function () {
    $regex = SystemConfig::get('regex_bdv');
    expect($regex)->not->toBeEmpty();
});
