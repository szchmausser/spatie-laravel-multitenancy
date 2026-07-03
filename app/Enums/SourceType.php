<?php

namespace App\Enums;

enum SourceType: string
{
    case AndroidPush = 'android_push';

    /**
     * Human-readable label for display in admin UI / logs.
     */
    public function label(): string
    {
        return match ($this) {
            self::AndroidPush => 'Android Push',
        };
    }

    /**
     * All known source types (not just currently active ones).
     * Use for validation rules, migration seeds, etc.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
