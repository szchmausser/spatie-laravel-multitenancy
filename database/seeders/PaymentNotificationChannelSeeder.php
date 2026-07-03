<?php

namespace Database\Seeders;

use App\Models\SystemConfig;
use Illuminate\Database\Seeder;

class PaymentNotificationChannelSeeder extends Seeder
{
    /**
     * Seed per-channel regex entries for each bank that has a base regex.
     *
     * Reads the existing regex_{bank} values and creates:
     *   - regex_{bank}_sms       (same as existing SMS regex)
     *   - regex_{bank}_android_push (same as SMS for now; operators update later)
     *
     * Existing entries are NOT overwritten (idempotent).
     * Old regex_{bank} keys are preserved (backward compat).
     */
    public function run(): void
    {
        $banks = ['bdv', 'bnc'];
        $channels = ['sms', 'android_push'];

        foreach ($banks as $bank) {
            $existingRegex = SystemConfig::get("regex_{$bank}");

            if ($existingRegex === null) {
                continue; // Skip banks without a base regex
            }

            foreach ($channels as $channel) {
                $key = "regex_{$bank}_{$channel}";

                $existing = SystemConfig::where('key', $key)->first();

                if ($existing !== null) {
                    continue; // Already exists, don't overwrite
                }

                SystemConfig::create([
                    'group' => 'reconciliation',
                    'key' => $key,
                    'value' => $existingRegex,
                    'type' => 'string',
                ]);
            }
        }
    }
}
