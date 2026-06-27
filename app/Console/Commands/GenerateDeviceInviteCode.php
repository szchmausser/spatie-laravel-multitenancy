<?php

namespace App\Console\Commands;

use App\Models\DeviceInviteCode;
use App\Models\Tenant;
use Illuminate\Console\Command;

class GenerateDeviceInviteCode extends Command
{
    protected $signature = 'device:generate-invite
        {tenant : The tenant ID or domain to scope the invite code to}
        {--days=7 : Number of days until the code expires (0 = never)}';

    protected $description = 'Generate a single-use device invite code for a tenant';

    public function handle(): int
    {
        $identifier = $this->argument('tenant');

        $tenant = Tenant::query()
            ->where('id', $identifier)
            ->orWhere('domain', $identifier)
            ->first();

        if (! $tenant) {
            $this->error("Tenant not found: {$identifier}");

            return self::FAILURE;
        }

        $days = (int) $this->option('days');
        $expiresAt = $days > 0 ? now()->addDays($days) : null;

        $code = DeviceInviteCode::create([
            'tenant_id' => $tenant->id,
            'code' => DeviceInviteCode::generate(),
            'expires_at' => $expiresAt,
        ]);

        $this->info("Invite code generated for tenant [{$tenant->name}] ({$tenant->domain}):");
        $this->line("  Code: {$code->code}");
        $this->line('  Expires: '.($expiresAt ? $expiresAt->toDateTimeString() : 'Never'));

        return self::SUCCESS;
    }
}
