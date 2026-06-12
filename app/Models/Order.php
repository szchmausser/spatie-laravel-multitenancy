<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class Order extends Model
{
    use HasFactory;
    use UsesLandlordConnection;

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'resource_id',
        'total_cents',
        'status',
        'expires_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'total_cents' => 'integer',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Sum of verified payment amounts in cents.
     *
     * Uses a query instead of relation aggregation to stay accurate
     * regardless of in-memory state.
     */
    public function getPaidCentsAttribute(): int
    {
        return $this->payments()
            ->where('status', PaymentStatus::Verified)
            ->sum('amount_cents');
    }

    /**
     * Remaining amount to pay in cents.
     */
    public function getRemainingCentsAttribute(): int
    {
        return max(0, $this->total_cents - $this->paid_cents);
    }

    /**
     * Whether the order is fully paid.
     */
    public function isFullyPaid(): bool
    {
        return $this->paid_cents >= $this->total_cents;
    }

    /**
     * Get the buyable (Plan or Resource).
     */
    public function getBuyableAttribute(): Plan|Resource|null
    {
        return $this->plan ?? $this->resource;
    }

    /**
     * Get the buyable type label.
     */
    public function getBuyableTypeAttribute(): string
    {
        return $this->plan_id !== null ? 'plan' : 'resource';
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
