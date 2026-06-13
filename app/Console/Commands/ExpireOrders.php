<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\OrderExpired;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ExpireOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire overdue pending orders and notify tenants';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Collect orders that will expire BEFORE updating them
        $overdueOrders = Order::query()
            ->where('status', OrderStatus::Pending)
            ->where('expires_at', '<', now())
            ->get();

        if ($overdueOrders->isEmpty()) {
            return Command::SUCCESS;
        }

        // Bulk update status
        $overdueOrders->each->update(['status' => OrderStatus::Expired]);

        // Group by tenant and notify each tenant's admin users
        $grouped = $overdueOrders->groupBy('tenant_id');

        foreach ($grouped as $tenantId => $orders) {
            $this->notifyTenant((int) $tenantId, $orders);
        }

        $this->info("Expired {$overdueOrders->count()} order(s) and notified affected tenants.");

        return Command::SUCCESS;
    }

    /**
     * Notify tenant admin users about expired orders.
     *
     * Switches to the tenant connection, fetches admin users (owner,
     * tenant-admin), and sends the OrderExpired notification.
     */
    private function notifyTenant(int $tenantId, $orders): void
    {
        try {
            $tenant = Tenant::on('landlord')->find($tenantId);

            if (! $tenant) {
                Log::warning('Tenant not found for order expiration notification', ['tenant_id' => $tenantId]);

                return;
            }

            $tenant->makeCurrent();

            $adminUsers = User::query()
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['owner', 'tenant-admin']))
                ->get();

            if ($adminUsers->isEmpty()) {
                return;
            }

            foreach ($orders as $order) {
                Notification::send($adminUsers, new OrderExpired($order));
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to notify tenant about expired orders', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
