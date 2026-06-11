<?php

namespace App\Enums;

enum ActorType: string
{
    case Landlord = 'landlord';
    case Tenant = 'tenant';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Landlord => 'Landlord Admin',
            self::Tenant => 'Tenant User',
            self::System => 'System',
        };
    }
}
