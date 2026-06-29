<?php

namespace App\Jobs;

use App\Enums\CancellationType;
use App\Events\PaymentCancelled;
use App\Events\PaymentVerified;
use App\Models\PaymentMatch;
use App\Models\PaymentNotification;
use App\Models\SystemConfig;
use App\Services\Payment\PaymentNotificationParser;
use App\Services\Payment\ReconciliationOrchestrator;
use App\Services\Payment\ReconciliationResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class IngestPaymentNotification implements NotTenantAware, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public PaymentNotification $notification,
    ) {}

    /**
     * Execute the job.
     *
     * Flow:
     * 1. Parse notification via PaymentNotificationParser
     * 2. If parse fails → mark parse_status = 'failed', return
     * 3. DB::transaction: create PaymentMatch, run orchestrator
     * 4. Update parse_status = 'parsed'
     * 5. After commit: dispatch PaymentVerified / PaymentCancelled events
     */
    public function handle(): void
    {
        /** @var PaymentNotificationParser $parser */
        $parser = app(PaymentNotificationParser::class);
        $parsed = $parser->parse($this->notification->bank_code, $this->notification->raw_text);

        if ($parsed === null) {
            $this->notification->markFailed('Regex did not match');

            return;
        }

        $result = DB::transaction(function () use ($parsed) {
            // Create PaymentMatch (idempotent via firstOrCreate)
            $match = PaymentMatch::createFromParsed($this->notification, $parsed);

            // Run the reconciliation orchestrator
            $orchestrator = app(ReconciliationOrchestrator::class);

            return $orchestrator->run($match);
        });

        // After transaction succeeds, update parse_status
        $this->notification->markParsed($parsed);

        // Dispatch events AFTER commit (IC-4)
        $this->dispatchPostCommitEvents($result);
    }

    /**
     * Dispatch events after the database transaction has committed.
     */
    private function dispatchPostCommitEvents(ReconciliationResult $result): void
    {
        if ($result->verifiedPayment !== null) {
            $shadowMode = (bool) SystemConfig::get('reconciliation.shadow_mode_enabled', false);

            if (! $shadowMode) {
                event(new PaymentVerified($result->verifiedPayment));
            }
        }

        if ($result->cancelledPayment !== null) {
            event(new PaymentCancelled(
                $result->cancelledPayment,
                CancellationType::SystemDuplicate,
                $result->cancelledReason,
            ));
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $e): void
    {
        $this->notification->markFailed($e?->getMessage() ?? 'Unknown error');
    }
}
