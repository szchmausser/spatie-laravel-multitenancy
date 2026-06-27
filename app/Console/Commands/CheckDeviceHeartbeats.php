<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\Landlord;
use App\Models\SystemConfig;
use App\Notifications\SystemAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class CheckDeviceHeartbeats extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'devices:check-heartbeats';

    /**
     * The console command description.
     */
    protected $description = 'Check for devices that missed heartbeats and alert admins';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $interval = (int) SystemConfig::get('device.heartbeat_interval_minutes', 5);
        $timeoutMinutes = $interval * 3;

        $offlineDevices = Device::query()
            ->where('is_active', true)
            ->where(function ($query) use ($timeoutMinutes) {
                $query->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '<', now()->subMinutes($timeoutMinutes));
            })
            ->get();

        if ($offlineDevices->isEmpty()) {
            return Command::SUCCESS;
        }

        $admins = Landlord::all();

        foreach ($offlineDevices as $device) {
            $since = $device->last_heartbeat_at
                ? $device->last_heartbeat_at->diffForHumans()
                : 'nunca';

            // Auto-deactivate: the device is no longer reachable.
            // If it comes back, the Android app will re-register and
            // get a new token via the deduplication logic in register().
            $device->update(['is_active' => false]);

            Notification::send($admins, new SystemAlert(
                type: 'device_offline',
                message: "Dispositivo [{$device->name}] sin heartbeat desde {$since}. Desactivado automáticamente.",
                severity: 'warning',
            ));
        }

        $this->warn("Found {$offlineDevices->count()} offline device(s). Deactivated and alerts sent.");

        return Command::SUCCESS;
    }
}
