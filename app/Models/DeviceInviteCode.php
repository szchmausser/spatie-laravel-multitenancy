<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

/**
 * Single-use invite code scoped to a tenant.
 *
 * Each code allows exactly one device to register and be automatically
 * activated for that tenant. Once used, the code cannot be reused.
 */
class DeviceInviteCode extends Model
{
    use HasFactory, UsesLandlordConnection;

    protected $fillable = [
        'tenant_id',
        'code',
        'used_at',
        'expires_at',
        'created_by',
        'device_id',
    ];

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The tenant that owns this invite code.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The admin user who created this invite code.
     *
     * Admin users are always Landlord instances (landlord connection),
     * NOT tenant User instances. Using User::class would try to query
     * the tenant database for the `users` table, which does not exist
     * there and would fail with a "no existe la relación «users»" error
     * on any landlord-domain page that eager-loads this relationship.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Landlord::class, 'created_by');
    }

    /**
     * The device that was registered using this code.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Check whether this code has already been used.
     */
    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    /**
     * Check whether this code has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Check whether this code is valid (not used, not expired).
     */
    public function isValid(): bool
    {
        return ! $this->isUsed() && ! $this->isExpired();
    }

    /**
     * Mark this code as used by a device.
     */
    public function consume(int $deviceId): void
    {
        $this->update([
            'used_at' => now(),
            'device_id' => $deviceId,
        ]);
    }

    /**
     * Generate a human-readable invite code.
     *
     * Format: INV-XXXXXXXX where X is uppercase alphanumeric (no lookalikes).
     */
    public static function generate(): string
    {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0, O, 1, I
        $random = '';

        for ($i = 0; $i < 8; $i++) {
            $random .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return 'INV-'.$random;
    }
}
