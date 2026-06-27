<?php

namespace App\Enums;

enum CancellationType: string
{
    case Manual = 'manual';
    case SystemDuplicate = 'system_duplicate';
    case SystemExpired = 'system_expired';
    case MethodChanged = 'method_changed';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::SystemDuplicate => 'System Duplicate',
            self::SystemExpired => 'System Expired',
            self::MethodChanged => 'Method Changed',
        };
    }
}
