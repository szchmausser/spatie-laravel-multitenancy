<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class Device extends Model
{
    use HasFactory, UsesLandlordConnection;

    protected $fillable = [
        'name',
        'token',
        'android_device_id',
        'last_heartbeat_at',
        'last_heartbeat_ip',
        'is_active',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'last_heartbeat_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The tenant that owns this device, if registered via invite code.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Payment notifications sent by this device.
     */
    public function paymentNotifications(): HasMany
    {
        return $this->hasMany(PaymentNotification::class);
    }

    /**
     * Heartbeat records for this device.
     */
    public function heartbeats(): HasMany
    {
        return $this->hasMany(DeviceHeartbeat::class);
    }
}
