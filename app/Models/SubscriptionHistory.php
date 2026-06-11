<?php

namespace App\Models;

use App\Enums\SubscriptionEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class SubscriptionHistory extends Model
{
    use HasFactory;
    use UsesLandlordConnection;

    protected $table = 'subscription_history';

    protected $fillable = [
        'subscription_id',
        'tenant_id',
        'event_type',
        'actor_id',
        'ip_address',
        'user_agent',
        'reason',
        'old_plan_name',
        'old_plan_price_cents',
        'old_plan_features',
        'old_status',
        'new_plan_name',
        'new_plan_price_cents',
        'new_plan_features',
        'new_status',
        'amount_cents',
        'currency',
        'billing_period_start',
        'billing_period_end',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => SubscriptionEventType::class,
            'old_plan_features' => 'array',
            'new_plan_features' => 'array',
            'correlation_id' => 'string',
            'billing_period_start' => 'datetime',
            'billing_period_end' => 'datetime',
        ];
    }

    /**
     * Record a subscription history entry.
     */
    public static function record(array $attributes): static
    {
        return static::create($attributes);
    }

    /**
     * Get the subscription for this history entry.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Get the tenant for this history entry.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the actor (user) who initiated the change.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
