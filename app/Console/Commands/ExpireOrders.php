<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Console\Command;

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
    protected $description = 'Expire overdue pending orders';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $expired = Order::query()
            ->where('status', OrderStatus::Pending)
            ->where('expires_at', '<', now())
            ->update(['status' => OrderStatus::Expired]);

        if ($expired > 0) {
            $this->info("Expired {$expired} order(s).");
        }

        return Command::SUCCESS;
    }
}
