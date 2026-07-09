<?php

namespace App\Models;

use Database\Factories\ResourceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

/**
 * A downloadable resource published by the SaaS owner.
 *
 * Resources are global — they live in the landlord database and are
 * NOT scoped to any tenant. A tenant gains access to a resource
 * either implicitly (its plan includes the resource in the
 * `plan_resource` pivot) or explicitly (an `Entitlement` row
 * exists for the tenant+user+resource triple).
 *
 * The `file_path` column stores a path relative to the landlord's
 * default storage disk; the controller uses `Storage::download()`
 * to stream the file. We keep `file_size_bytes` and `mime_type` as
 * denormalised columns so the index page can render file
 * information without touching the filesystem.
 */
class Resource extends Model
{
    /** @use HasFactory<ResourceFactory> */
    use HasFactory;

    use UsesLandlordConnection;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'file_path',
        'file_size_bytes',
        'mime_type',
        'is_premium',
        'price_cents',
        'is_active',
    ];

    /**
     * The accessors to append — defaults to empty so we only append
     * what the current request needs (landlord index sets this).
     *
     * @var list<string>
     */
    protected $appends = [];

    /**
     * Temporary appends for the landlord resource index.
     * These are set by the controller before serialization.
     */
    public bool $has_plans_assigned = false;
    public array $included_in_plan_names = [];

    public function getHasPlansAssignedAttribute(): bool
    {
        return $this->plans()->exists();
    }

    public function getIncludedInPlanNamesAttribute(): array
    {
        return $this->plans()->pluck('plans.name')->toArray();
    }

    protected function casts(): array
    {
        return [
            'is_premium' => 'boolean',
            'is_active' => 'boolean',
            'price_cents' => 'integer',
            'file_size_bytes' => 'integer',
        ];
    }

    /**
     * Scope to only active (published) resources.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to only premium resources.
     */
    public function scopePremium(Builder $query): Builder
    {
        return $query->where('is_premium', true);
    }

    /**
     * Entitlements that have been granted for this resource.
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }

    /**
     * The plans that include this resource.
     */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class);
    }

    /**
     * Human-readable file size (e.g. "4.2 MB", "512 B").
     *
     * Uses number_format with a fixed number of decimals so the
     * output is stable for the tests and the UI (round() drops
     * trailing zeros, which produced "2 KB" instead of "2.0 KB"
     * for 2048 bytes).
     */
    public function formattedFileSize(): ?string
    {
        if ($this->file_size_bytes === null) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = (float) $this->file_size_bytes;
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        $decimals = $i === 0 ? 0 : 1;

        return number_format($bytes, $decimals).' '.$units[$i];
    }
}
