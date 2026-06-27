<?php

namespace App\Console\Commands;

use App\Models\DeviceHeartbeat;
use App\Models\SystemConfig;
use Illuminate\Console\Command;

class PurgeDeviceHeartbeats extends Command
{
    protected $signature = 'device-heartbeats:purge';

    protected $description = 'Purga heartbeats más antiguos que los días configurados';

    public function handle(): int
    {
        $days = (int) SystemConfig::get('device.heartbeat_retention_days', 30);

        $deleted = DeviceHeartbeat::where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Purged {$deleted} heartbeat records older than {$days} days.");

        return self::SUCCESS;
    }
}
