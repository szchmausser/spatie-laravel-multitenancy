<?php

namespace App\Console\Commands;

use App\Enums\CancellationType;
use App\Enums\PaymentStatus;
use App\Events\PaymentCancelled;
use App\Models\Payment;
use App\Models\SystemConfig;
use App\Services\Payment\PaymentService;
use Illuminate\Console\Command;

class ExpirePendingPayments extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'payments:expire-pending';

    /**
     * The console command description.
     */
    protected $description = 'Cancel pending payments older than match_window_hours + 24h buffer';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $windowHours = (int) SystemConfig::get('reconciliation.match_window_hours', 72);
        $cutoff = now()->subHours($windowHours + 24);

        $this->info("Expiring pending payments created before {$cutoff}...");

        $expiredPayments = Payment::where('status', PaymentStatus::Pending)
            ->where('created_at', '<', $cutoff)
            ->get();

        $count = 0;

        /** @var PaymentService $paymentService */
        $paymentService = app(PaymentService::class);

        foreach ($expiredPayments as $payment) {
            $paymentService->cancelPayment(
                $payment,
                CancellationType::SystemExpired,
                'system',
                'Pago expiró sin conciliación automática',
            );

            // Dispatch event after cancellation (IC-4)
            event(new PaymentCancelled(
                $payment->fresh(),
                CancellationType::SystemExpired,
                'Pago expiró sin conciliación automática',
            ));

            $count++;
        }

        $this->info("Cancelled {$count} expired pending payment(s).");

        return Command::SUCCESS;
    }
}
