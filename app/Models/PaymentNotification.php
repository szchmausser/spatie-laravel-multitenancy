<?php

namespace App\Models;

use App\Enums\SourceType;
use App\Services\Payment\ParsedPayment;
use App\Services\Payment\PaymentNotificationParser;
use Database\Factories\PaymentNotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class PaymentNotification extends Model
{
    /** @use HasFactory<PaymentNotificationFactory> */
    use HasFactory, UsesLandlordConnection;

    /**
     * The table associated with the model.
     */
    protected $table = 'payment_notifications';

    /**
     * Only parse_status and parsed fields are mass-assignable.
     * Raw fields (bank_code, raw_text, dedup_hash) are immutable after creation.
     */
    protected $fillable = [
        'parse_status',
        'parsed_data',
        'parse_error',
        'parsed_at',
        'source_type',
    ];

    protected function casts(): array
    {
        return [
            'parsed_data' => 'array',
            'parsed_at' => 'datetime',
            'source_type' => SourceType::class,
        ];
    }

    /**
     * Compute a deterministic dedup hash for bank_code + raw_text.
     *
     * Delegates field normalization to PaymentNotificationParser so that
     * semantically identical payments from different sources (masked vs full
     * phone, 2 vs 4 digit dates) produce the same hash.
     */
    public static function computeDedupHash(string $bankCode, string $rawText): string
    {
        $normalized = app(PaymentNotificationParser::class)
            ->normalizeForDedup($bankCode, $rawText);

        return hash('sha256', $bankCode.$normalized);
    }

    /**
     * Mark this notification as successfully parsed.
     */
    public function markParsed(ParsedPayment $parsed): void
    {
        $this->update([
            'parse_status' => 'parsed',
            'parsed_data' => array_merge([
                'amount_cents' => $parsed->amountCents,
                'reference' => $parsed->reference,
                'sender_phone_last4' => $parsed->senderPhoneLast4,
            ], $parsed->rawGroups ? ['raw_groups' => $parsed->rawGroups] : []),
            'parsed_at' => now(),
            'parse_error' => null,
        ]);
    }

    /**
     * Mark this notification as failed to parse.
     */
    public function markFailed(string $error): void
    {
        $this->update([
            'parse_status' => 'failed',
            'parse_error' => $error,
            'parsed_at' => now(),
        ]);
    }

    /**
     * Scope: notifications pending parsing.
     */
    public function scopePending($query)
    {
        return $query->where('parse_status', 'pending');
    }

    /**
     * Scope: notifications that failed parsing.
     */
    public function scopeFailed($query)
    {
        return $query->where('parse_status', 'failed');
    }

    /**
     * The PaymentMatch linked to this notification, if any.
     */
    public function match(): HasOne
    {
        return $this->hasOne(PaymentMatch::class, 'payment_notification_id');
    }

    /**
     * Accessor to retrieve the raw parsed payment DTO from stored JSON.
     */
    public function getParsedPaymentAttribute(): ?ParsedPayment
    {
        if ($this->parsed_data === null) {
            return null;
        }

        return new ParsedPayment(
            amountCents: $this->parsed_data['amount_cents'],
            reference: $this->parsed_data['reference'],
            senderPhoneLast4: $this->parsed_data['sender_phone_last4'] ?? null,
            parsedAt: $this->parsed_at ? $this->parsed_at->copy() : null,
            rawGroups: $this->parsed_data['raw_groups'] ?? null,
        );
    }
}
