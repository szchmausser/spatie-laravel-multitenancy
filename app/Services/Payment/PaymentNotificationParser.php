<?php

namespace App\Services\Payment;

use App\Models\SystemConfig;
use Carbon\Carbon;

class PaymentNotificationParser
{
    /**
     * Parse a raw notification text for a given bank.
     *
     * Returns null when the notification cannot be parsed (unknown bank,
     * regex doesn't match, insufficient data).
     */
    public function parse(string $bankCode, string $text): ?ParsedPayment
    {
        // 1. Get regex for this bank (cached 1h via SystemConfig)
        $regex = SystemConfig::get("regex_{$bankCode}");

        if (! $regex) {
            return null;
        }

        // 2. Apply regex
        if (preg_match($regex, $text, $matches) !== 1) {
            return null;
        }

        // 3. Validate required groups
        if (empty($matches['amount']) || empty($matches['reference'])) {
            return null;
        }

        // 4. Normalize and return
        $namedGroups = array_filter($matches, fn ($key) => is_string($key), ARRAY_FILTER_USE_KEY);

        return new ParsedPayment(
            amountCents: $this->normalizeAmount($matches['amount']),
            reference: normalizeRef($matches['reference']),
            senderPhoneLast4: $this->extractLast4($matches['phone'] ?? null),
            parsedAt: $this->parseDate(
                $matches['date'] ?? null,
                $matches['time'] ?? null,
                $this->getDateFormat($bankCode)
            ),
            rawGroups: $namedGroups,
        );
    }

    /**
     * Get the date format for a bank (hardcoded — formats don't change frequently).
     */
    private function getDateFormat(string $bankCode): string
    {
        return match ($bankCode) {
            'bdv' => 'd/m/Y H:i',
            'bnc' => 'd/m/Y H:i',
            default => 'd/m/Y H:i',
        };
    }

    /**
     * Normalize an amount string to cents (integer).
     *
     * Venezuelan format: thousands separator with dot, decimal with comma.
     * Example: "3.000,00" → 300000, "10455,00" → 1045500
     */
    public function normalizeAmount(string $raw): int
    {
        $clean = str_replace('.', '', $raw);
        $clean = str_replace(',', '.', $clean);

        return (int) round((float) $clean * 100);
    }

    /**
     * Extract the last 4 digits from a phone number.
     * Handles masked phones like "0416***9503".
     */
    public function extractLast4(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $phone);

        return strlen($digits) >= 4 ? substr($digits, -4) : $digits;
    }

    /**
     * Parse a date+time string using the bank-specific format.
     */
    private function parseDate(?string $date, ?string $time, string $format): ?Carbon
    {
        if (! $date) {
            return null;
        }

        $full = $time ? "{$date} {$time}" : $date;

        $parsed = Carbon::createFromFormat($format, $full);

        return $parsed !== false ? $parsed->timezone('America/Caracas') : null;
    }
}
