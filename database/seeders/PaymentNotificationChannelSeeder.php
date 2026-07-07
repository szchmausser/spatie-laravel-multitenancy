<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class PaymentNotificationChannelSeeder extends Seeder
{
    /**
     * No-op — channel-specific regexes are now seeded directly by SystemConfigSeeder.
     *
     * This seeder is kept for backward compatibility with DatabaseSeeder but does nothing.
     */
    public function run(): void
    {
        // Channel-specific regexes (regex_{bank}_{channel}) are now seeded
        // directly in SystemConfigSeeder. No conversion needed.
    }
}
