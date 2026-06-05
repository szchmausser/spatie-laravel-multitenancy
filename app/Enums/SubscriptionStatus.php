<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case Trialing = 'trialing';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Trialing => 'Trialing',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function isTrialing(): bool
    {
        return $this === self::Trialing;
    }

    public function isCancelled(): bool
    {
        return $this === self::Cancelled;
    }

    public function isExpired(): bool
    {
        return $this === self::Expired;
    }
}
