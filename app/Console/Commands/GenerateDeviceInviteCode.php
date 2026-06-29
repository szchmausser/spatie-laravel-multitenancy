<?php

namespace App\Console\Commands;

use App\Models\DeviceInviteCode;
use Illuminate\Console\Command;

class GenerateDeviceInviteCode extends Command
{
    protected $signature = 'device:generate-invite
        {--days=7 : Number of days until the code expires (0 = never)}
        {--created-by= : Optional Landlord user ID who created this code}';

    protected $description = 'Generate a single-use device invite code';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $expiresAt = $days > 0 ? now()->addDays($days) : null;

        $code = DeviceInviteCode::create([
            'code' => DeviceInviteCode::generate(),
            'expires_at' => $expiresAt,
            'created_by' => $this->option('created-by') ? (int) $this->option('created-by') : null,
        ]);

        $this->info('Invite code generated:');
        $this->line("  Code: {$code->code}");
        $this->line('  Expires: '.($expiresAt ? $expiresAt->toDateTimeString() : 'Never'));

        return self::SUCCESS;
    }
}
