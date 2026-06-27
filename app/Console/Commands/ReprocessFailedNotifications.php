<?php

namespace App\Console\Commands;

use App\Jobs\IngestPaymentNotification;
use App\Models\PaymentNotification;
use Illuminate\Console\Command;

class ReprocessFailedNotifications extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'reconciliation:reprocess
        {--parse-status=failed : Parse status to filter notifications by}';

    /**
     * The console command description.
     */
    protected $description = 'Re-dispatch IngestPaymentNotification for notifications with a given parse status';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $parseStatus = $this->option('parse-status');

        $notifications = PaymentNotification::where('parse_status', $parseStatus)->get();

        $count = 0;

        foreach ($notifications as $notification) {
            IngestPaymentNotification::dispatch($notification);
            $count++;
        }

        $this->info("Dispatched {$count} IngestPaymentNotification job(s) for parse_status [{$parseStatus}].");

        return Command::SUCCESS;
    }
}
