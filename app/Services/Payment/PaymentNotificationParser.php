<?php

namespace App\Services\Payment;

use App\Enums\BankCode;
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
            'bdv' => 'd-m-y H:i',
            'bnc' => 'd/m/y H:i',
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
     * Strip non-digits from a phone, return first 4 + last 4 digits.
     *
     * If fewer than 4 digits remain after stripping, returns empty string.
     */
    public function canonicalPhone(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($digits) < 4) {
            return '';
        }

        return substr($digits, 0, 4).substr($digits, -4);
    }

    /**
     * Try multiple date formats and return ISO 8601 on first success.
     *
     * If none match, returns raw "$date $time" — never null, never throws.
     *
     * @param  string[]  $formats
     */
    public function parseDateMultiFormat(string $date, string $time, array $formats): string
    {
        $full = "{$date} {$time}";

        foreach ($formats as $format) {
            $dt = \DateTime::createFromFormat($format, $full);

            if ($dt !== false) {
                return $dt->format('Y-m-d\TH:i:s');
            }
        }

        return $full;
    }

    /**
     * Normalize a raw notification body for deterministic dedup hashing.
     *
     * Uses the same regex as parse(), extracts amount, phone, date, and
     * reference, normalizes each field, and returns a 4-field pipe string.
     *
     * The result is intended for: hash('sha256', $bankCode . $normalized)
     */
    public function normalizeForDedup(string $bankCode, string $rawBody): string
    {
        $regex = SystemConfig::get("regex_{$bankCode}");

        if (! $regex || preg_match($regex, $rawBody, $matches) !== 1) {
            return $rawBody;
        }

        $amount = (string) $this->normalizeAmount($matches['amount'] ?? '0');

        $bank = BankCode::tryFrom($bankCode);
        $rawPhone = $matches['phone'] ?? '';

        if ($bank && $bank->appliesCanonicalPhone()) {
            $phone = $this->canonicalPhone($rawPhone);
        } else {
            $phone = preg_replace('/[^0-9]/', '', $rawPhone);
        }

        $date = $this->parseDateMultiFormat(
            $matches['date'] ?? '',
            $matches['time'] ?? '',
            $bank ? $bank->dateFormats() : [],
        );

        $ref = normalizeRef($matches['reference'] ?? '');

        return "{$amount}|{$phone}|{$date}|{$ref}";
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

        try {
            return Carbon::createFromFormat($format, $full)->timezone('America/Caracas');
        } catch (\Throwable) {
            return null;
        }
    }
}
