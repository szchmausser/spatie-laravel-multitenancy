<?php

namespace App\Services\Payment;

use App\Models\Payment;

class ReconciliationResult
{
    public function __construct(
        public ?Payment $verifiedPayment = null,
        public ?Payment $cancelledPayment = null,
        public ?string $cancelledReason = null,
    ) {}
}
