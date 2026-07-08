<?php

namespace App\Services\Payment;

use Carbon\Carbon;

class ParsedPayment
{
    public function __construct(
        public readonly int $amountCents,
        public readonly ?string $reference,
        public readonly ?string $senderPhoneLast4,
        public readonly ?Carbon $parsedAt,
        public readonly ?array $rawGroups = null,
        public readonly ?string $senderPhoneNumber = null,
        public readonly ?string $senderPhoneFirst4 = null,
    ) {}
}
