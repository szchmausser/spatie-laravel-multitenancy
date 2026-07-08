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
        'parsed_sender_phone_number',
        'parsed_sender_phone_first4',
        'parsed_bank_code',
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
     *
     * 4-step dedup algorithm:
     * 1. Idempotency — same notification → return existing
     * 2. Same reference, unmatched exists → reuse (link notification)
     * 3. Same reference, matched exists → create duplicate_attempt
     * 4. No match → create new unmatched
     */
    public static function createFromParsed(PaymentNotification $notification, ParsedPayment $parsed): static
    {
        // Step 1: Idempotency — same notification → return existing
        $existingByNotification = static::where('payment_notification_id', $notification->id)->first();

        if ($existingByNotification !== null) {
            return $existingByNotification;
        }

        // Reference-based dedup only if reference is present
        $reference = $parsed->reference;

        if ($reference !== null && $reference !== '') {
            // Step 2: Unmatched match for same reference → reuse (link notification)
            $existingUnmatched = static::where('parsed_reference', $reference)
                ->where('match_status', 'unmatched')
                ->first();

            if ($existingUnmatched !== null) {
                $existingUnmatched->update(['payment_notification_id' => $notification->id]);

                return $existingUnmatched;
            }

            // Step 3: Matched match for same reference → mark duplicate
            $existingMatched = static::where('parsed_reference', $reference)
                ->where('match_status', 'matched')
                ->first();

            if ($existingMatched !== null) {
                return static::create([
                    'payment_notification_id' => $notification->id,
                    'parsed_reference' => $reference,
                    'parsed_amount_cents' => $parsed->amountCents,
                    'parsed_sender_phone_last4' => $parsed->senderPhoneLast4,
                    'parsed_sender_phone_number' => $parsed->senderPhoneNumber,
                    'parsed_sender_phone_first4' => $parsed->senderPhoneFirst4,
                    'parsed_bank_code' => $notification->bank_code,
                    'match_status' => 'duplicate_attempt',
                ]);
            }
        }

        // Step 4: No match → create new unmatched
        return static::create([
            'payment_notification_id' => $notification->id,
            'parsed_reference' => $reference,
            'parsed_amount_cents' => $parsed->amountCents,
            'parsed_sender_phone_last4' => $parsed->senderPhoneLast4,
            'parsed_sender_phone_number' => $parsed->senderPhoneNumber,
            'parsed_sender_phone_first4' => $parsed->senderPhoneFirst4,
            'parsed_bank_code' => $notification->bank_code,
            'match_status' => 'unmatched',
        ]);
    }
}
