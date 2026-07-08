<?php

namespace App\Services\Payment;

use App\Enums\BankCode;
use App\Models\Payment;
use App\Models\PaymentMatch;

class PaymentMatchGuard
{
    /**
     * Validate bank and phone between a PaymentMatch and a Payment.
     *
     * @return array{field: string, payment_value: string, notification_value: string, match_id: int}|null
     */
    public static function validate(
        PaymentMatch $match,
        Payment $payment,
    ): ?array {
        // 1. Load pagoMovilDetail
        $payment->load('pagoMovilDetail');
        $detail = $payment->pagoMovilDetail;

        if ($detail === null) {
            return null;
        }

        // 2. Resolve bank code
        $bankCode = BankCode::tryFrom($match->parsed_bank_code ?? '');

        if ($bankCode === null) {
            return null;
        }

        // 3. Validate bank
        $notificationBankName = $bankCode->name();
        $paymentBankName = $detail->sender_bank;

        if ($notificationBankName !== $paymentBankName) {
            return [
                'field' => 'sender_bank',
                'payment_value' => $paymentBankName ?? 'N/A',
                'notification_value' => $notificationBankName,
                'match_id' => $match->id,
            ];
        }

        // 4. Validate phone
        if ($match->parsed_sender_phone_first4 === null) {
            return null;
        }

        $paymentDigits = preg_replace('/\D/', '', $detail->sender_phone ?? '');
        $paymentFirst4 = strlen($paymentDigits) >= 4 ? substr($paymentDigits, 0, 4) : null;
        $paymentLast4 = strlen($paymentDigits) >= 4 ? substr($paymentDigits, -4) : null;

        if ($bankCode->appliesCanonicalPhone()) {
            // BNC: compare first4 + last4
            if ($paymentFirst4 !== $match->parsed_sender_phone_first4 || $paymentLast4 !== $match->parsed_sender_phone_last4) {
                return [
                    'field' => 'sender_phone',
                    'payment_value' => $detail->sender_phone ?? 'N/A',
                    'notification_value' => $match->parsed_sender_phone_number ?? 'N/A',
                    'match_id' => $match->id,
                ];
            }
        } else {
            // BDV: compare full digits
            $notificationDigits = preg_replace('/\D/', '', $match->parsed_sender_phone_number ?? '');

            if ($paymentDigits !== $notificationDigits) {
                return [
                    'field' => 'sender_phone',
                    'payment_value' => $detail->sender_phone ?? 'N/A',
                    'notification_value' => $match->parsed_sender_phone_number ?? 'N/A',
                    'match_id' => $match->id,
                ];
            }
        }

        return null;
    }
}
