<?php

namespace App\Actions;

use App\Enums\SourceType;
use App\Jobs\IngestPaymentNotification;
use App\Models\PaymentNotification;

class IngestNotificationAction
{
    /**
     * Ingest a payment notification from any source.
     *
     * Computes the dedup hash, creates the notification record,
     * and dispatches the async processing job.
     */
    public function execute(
        string $bankCode,
        string $rawBody,
        SourceType $sourceType,
        ?int $deviceId = null,
    ): PaymentNotification {
        $hash = PaymentNotification::computeDedupHash($bankCode, $rawBody);

        $notification = PaymentNotification::forceCreate([
            'device_id' => $deviceId,
            'bank_code' => $bankCode,
            'raw_text' => $rawBody,
            'dedup_hash' => $hash,
            'source_type' => $sourceType->value,
            'parse_status' => 'pending',
        ]);

        IngestPaymentNotification::dispatch($notification);

        return $notification;
    }
}
