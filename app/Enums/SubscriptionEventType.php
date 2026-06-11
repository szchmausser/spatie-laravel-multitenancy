<?php

namespace App\Enums;

enum SubscriptionEventType: string
{
    case SubscriptionCreated = 'subscription_created';
    case PlanChanged = 'plan_changed';
    case SubscriptionExpired = 'subscription_expired';

    public function label(): string
    {
        return match ($this) {
            self::SubscriptionCreated => 'Subscription Created',
            self::PlanChanged => 'Plan Changed',
            self::SubscriptionExpired => 'Subscription Expired',
        };
    }
}
