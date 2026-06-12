<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidPaymentAmountException extends RuntimeException
{
    public function __construct(
        private readonly int $expected,
        private readonly int $received,
    ) {
        parent::__construct(
            "Payment amount mismatch: expected {$expected} cents, received {$received} cents."
        );
    }

    public function getExpected(): int
    {
        return $this->expected;
    }

    public function getReceived(): int
    {
        return $this->received;
    }
}
