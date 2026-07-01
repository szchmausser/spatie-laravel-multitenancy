<?php

namespace App\Enums;

enum BankCode: string
{
    case Bdv = 'bdv';
    case Bnc = 'bnc';

    public function code(): string
    {
        return $this->value;
    }

    public function name(): string
    {
        return match ($this) {
            self::Bdv => 'Banco de Venezuela',
            self::Bnc => 'Banco Nacional de Crédito',
        };
    }

    public function appliesCanonicalPhone(): bool
    {
        return match ($this) {
            self::Bdv => false,
            self::Bnc => true,
        };
    }

    /**
     * @return string[]
     */
    public function dateFormats(): array
    {
        return match ($this) {
            self::Bdv => ['j/n/Y G:i'],
            self::Bnc => ['d/m/y H:i', 'd/m/Y H:i'],
        };
    }

    public function androidPackage(): ?string
    {
        return match ($this) {
            self::Bdv => 'com.bdv.pagomovil',
            self::Bnc => 'com.bnc.pagomovil',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code(),
            'name' => $this->name(),
            'applies_canonical_phone' => $this->appliesCanonicalPhone(),
            'date_formats' => $this->dateFormats(),
            'android_package' => $this->androidPackage(),
        ];
    }
}
