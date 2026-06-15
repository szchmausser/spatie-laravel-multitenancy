<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class Payment extends Model
{
    use HasFactory;
    use UsesLandlordConnection;

    protected $fillable = [
        'tenant_id',
        'order_id',
        'amount_cents',
        'currency',
        'payment_method',
        'payment_method_config_id',
        'transaction_id',
        'status',
        'verified_by',
        'verified_at',
        'cancellation_reason',
        'cancelled_by',
        'cancelled_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount_cents' => 'integer',
            'verified_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(Landlord::class, 'verified_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(Landlord::class, 'cancelled_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function paymentMethodConfig(): BelongsTo
    {
        return $this->belongsTo(PaymentMethodConfig::class);
    }

    public function pagoMovilDetail(): HasOne
    {
        return $this->hasOne(PagoMovilDetail::class);
    }

    public function bankTransferDetail(): HasOne
    {
        return $this->hasOne(BankTransferDetail::class);
    }

    /**
     * Get the payment-specific details based on the payment method.
     */
    public function getDetailsAttribute(): PagoMovilDetail|BankTransferDetail|null
    {
        return match ($this->payment_method) {
            'pago_movil' => $this->pagoMovilDetail,
            'bank_transfer' => $this->bankTransferDetail,
            default => null,
        };
    }
}
