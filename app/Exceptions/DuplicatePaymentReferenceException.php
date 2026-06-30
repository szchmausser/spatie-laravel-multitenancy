<?php

namespace App\Exceptions;

use RuntimeException;

class DuplicatePaymentReferenceException extends RuntimeException
{
    public function __construct(
        private readonly string $reference,
        private readonly int $existingPaymentId,
    ) {
        parent::__construct(
            "La referencia {$reference} ya fue utilizada en el pago #{$existingPaymentId}. No puede reutilizar una referencia de pago ya verificada."
        );
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getExistingPaymentId(): int
    {
        return $this->existingPaymentId;
    }
}
