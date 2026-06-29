<?php

namespace App\Models;

use App\Enums\EntitlementGrantVia;
use Database\Factories\EntitlementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

/**
 * Records that a specific tenant is allowed to download a specific
 * resource.
 *
 * Entitlements are tenant-level: one row per (tenant, resource)
 * grants access to ALL users of that tenant. Any authenticated user
 * of the tenant can download a resource the tenant owns.
 *
 * The tenant_id is enforced and cascades on tenant deletion, so
 * wiping a tenant also wipes their entitlements.
 *
 * See EntitlementGrantVia for the meaning of the granted_via value.
 */
class Entitlement extends Model
{
    /** @use HasFactory<EntitlementFactory> */
    use HasFactory;

    use UsesLandlordConnection;

    protected $fillable = [
        'tenant_id',
        'resource_id',
        'granted_via',
        'granted_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'granted_via' => EntitlementGrantVia::class,
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The resource this entitlement unlocks.
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * The tenant that owns this entitlement.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Check whether this entitlement is still valid at the given
     * moment in time. An entitlement without an expiry is always
     * valid; one with `expires_at` in the past is not.
     */
    public function isValid(?\DateTimeInterface $at = null): bool
    {
        if ($this->expires_at === null) {
            return true;
        }

        $at = $at ?? now();

        return $this->expires_at->greaterThan($at);
    }
}
