<?php

namespace App\Models;

use App\Services\Payment\ParsedPayment;
use Database\Factories\PaymentMatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class PaymentMatch extends Model
{
    /** @use HasFactory<PaymentMatchFactory> */
    use HasFactory, UsesLandlordConnection;

    protected $fillable = [
        'payment_notification_id',
        'payment_id',
        'parsed_reference',
        'parsed_amount_cents',
        'parsed_sender_phone_last4',
        'match_status',
        'matched_at',
    ];

    protected function casts(): array
    {
        return [
            'matched_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(PaymentNotification::class, 'payment_notification_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Create a match from parsed notification data.
     * Idempotent: if a match already exists for this notification,
     * returns the existing record.
     */
    public static function createFromParsed(PaymentNotification $notification, ParsedPayment $parsed): static
    {
        return static::firstOrCreate(
            ['payment_notification_id' => $notification->id],
            [
                'parsed_reference' => $parsed->reference,
                'parsed_amount_cents' => $parsed->amountCents,
                'parsed_sender_phone_last4' => $parsed->senderPhoneLast4,
                'match_status' => 'unmatched',
            ]
        );
    }
}
