<?php

namespace App\Services\Payment;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentMatch;
use App\Models\SystemConfig;

class ReconciliationOrchestrator
{
    public function __construct(
        private readonly ?PaymentService $paymentService = null,
    ) {
        $this->paymentService ??= app(PaymentService::class);
    }

    /**
     * Run the reconciliation matching algorithm against a payment match.
     *
     * Must be called inside a DB::transaction for SELECT FOR UPDATE to work.
     * Steps executed in order:
     *   1. Normal matching — pending payment with same ref + amount + window
     *   2. One candidate → verified (or shadow)
     *   3. Multiple candidates → manual review queue
     *   4. No candidates → unmatched
     *
     * Note: duplicate detection is handled by the UNIQUE constraint on
     * payments.transaction_id — no additional code needed.
     */
    public function run(PaymentMatch $match): ReconciliationResult
    {
        // Guard: if not unmatched anymore, skip
        if ($match->match_status !== 'unmatched') {
            return new ReconciliationResult;
        }

        // Paso 1: Normal matching — find pending payments
        $windowHours = (int) SystemConfig::get('reconciliation.match_window_hours', 72);
        $windowStart = $match->created_at->subHours($windowHours);

        $candidates = Payment::where('status', PaymentStatus::Pending)
            ->where('amount_cents', $match->parsed_amount_cents)
            ->where('transaction_id', $match->parsed_reference)
            ->where('created_at', '>=', $windowStart)
            ->whereDoesntHave('paymentMatch', function ($q) {
                $q->where('match_status', 'matched');
            })
            ->lockForUpdate()
            ->get();

        $result = new ReconciliationResult;

        if ($candidates->count() === 1) {
            // Paso 2: Single candidate
            $payment = $candidates->first();

            if ($payment->status !== PaymentStatus::Pending) {
                // Race condition: payment already changed status
                $match->update(['match_status' => 'unmatched']);

                return $result;
            }

            $shadowMode = SystemConfig::get('reconciliation.shadow_mode_enabled', true);

            if ($shadowMode) {
                $match->update([
                    'payment_id' => $payment->id,
                    'match_status' => 'pending',
                ]);
            } else {
                $this->paymentService->verifyPayment($payment, null);

                $match->update([
                    'payment_id' => $payment->id,
                    'match_status' => 'matched',
                    'matched_at' => now(),
                ]);

                $result->verifiedPayment = $payment->fresh();
            }
        } elseif ($candidates->count() > 1) {
            // Paso 3: Multiple candidates
            $match->update(['match_status' => 'pending']);
        } else {
            // Paso 4: No candidates
            $match->update(['match_status' => 'unmatched']);
        }

        return $result;
    }

    /**
     * Run reverse matching — link an existing unmatched notification to a payment.
     *
     * This handles the common case where the bank notification arrives before
     * the customer finishes reporting the payment (~80% of transactions).
     *
     * Guards:
     * - Payment must still be Pending (race condition protection)
     * - Match must still be unmatched (not already linked)
     *
     * Shadow mode behavior:
     * - ON: match_status = 'pending' (suggest only, no auto-verify)
     * - OFF: PaymentService::verifyPayment() + match_status = 'matched'
     */
    public function runReverse(PaymentMatch $match, Payment $payment): ReconciliationResult
    {
        // Guard: payment must still be Pending
        if ($payment->status !== PaymentStatus::Pending) {
            return new ReconciliationResult;
        }

        // Guard: match must still be unmatched
        if ($match->match_status !== 'unmatched') {
            return new ReconciliationResult;
        }

        // Link payment to the match
        $match->update(['payment_id' => $payment->id]);

        $shadowMode = SystemConfig::get('reconciliation.shadow_mode_enabled', true);
        $result = new ReconciliationResult;

        if ($shadowMode) {
            $match->update(['match_status' => 'pending']);
        } else {
            $this->paymentService->verifyPayment($payment, null);
            $match->update([
                'match_status' => 'matched',
                'matched_at' => now(),
            ]);
            $result->verifiedPayment = $payment->fresh();
        }

        return $result;
    }
}
