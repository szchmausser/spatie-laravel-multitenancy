<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Models\Auth\Permission;
use App\Models\Auth\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Models\Concerns\ImplementsTenant;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;
use Spatie\Multitenancy\Models\Tenant as SpatieTenant;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tenant model with automatic provisioning.
 *
 * Extends Spatie's Tenant model and adds lifecycle callbacks that
 * automatically provision the tenant database when a new tenant is created.
 *
 * Provisioning steps (executed in the `creating` callback):
 * 0. assertTenantsTableExists() - Validates the landlords table exists
 * 1. createDatabase() - Creates the physical PostgreSQL database
 * 2. configureTenantConnection() - Points the 'tenant' connection to the new DB
 * 3. runMigrations() - Runs Laravel migrations on the new tenant database
 *
 * After the INSERT, the `created` listener ensures every tenant has a
 * subscription: if the seeder (or any caller) set `assignPlanSlug` to a
 * known plan slug, that plan is used; otherwise the system default
 * ('free') is assigned. No tenant can exist without a subscription.
 *
 * This model is used by the admin dashboard, seeders, and any code that
 * needs to create or manage tenants. It uses the landlord connection
 * (UsesLandlordConnection) since tenants are stored in the landlord database.
 */
class Tenant extends SpatieTenant implements IsTenant
{
    use HasFactory;
    use ImplementsTenant;
    use UsesLandlordConnection;

    protected $fillable = [
        'name',
        'domain',
        'database',
    ];

    /**
     * Plan slug to assign on creation.
     *
     * Set this property on a Tenant instance before calling save() to
     * request a specific plan. The `created` listener will look it up
     * by slug and create the matching subscription. If left null, the
     * listener falls back to the system default ('free').
     *
     * This is a transient in-memory flag — it is intentionally NOT in
     * $fillable and is NOT persisted as a column. It only affects the
     * behaviour of the next save() call.
     */
    public ?string $assignPlanSlug = null;

    /**
     * Register lifecycle callbacks for automatic provisioning and
     * default-plan assignment.
     *
     * The `creating` callback does the irreversible DB work (create
     * database, configure connection, run migrations). The `created`
     * callback runs after the INSERT succeeds and ensures every tenant
     * has exactly one subscription row, using either the slug set on
     * the model or the system default.
     */
    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            $tenant->assertTenantsTableExists();
            $tenant->createDatabase();
            $tenant->configureTenantConnection();
            $tenant->runMigrations();
        });

        static::created(function (Tenant $tenant): void {
            $tenant->ensureDefaultSubscription();
            $tenant->seedPermissions();
        });
    }

    /**
     * Get the subscription for this tenant.
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * Ensure this tenant has exactly one subscription row.
     *
     * Called by the `created` model event after the tenant INSERT.
     * If a subscription already exists (e.g. the seeder set one
     * explicitly), it is left untouched. Otherwise we look up the
     * plan identified by `$this->assignPlanSlug`, defaulting to the
     * 'free' plan if no slug was provided.
     *
     * The lookup happens on the landlord connection because the
     * `plans` and `subscriptions` tables live there. We never insert
     * a duplicate row thanks to the UNIQUE(tenant_id) constraint on
     * the subscriptions table and the early-return guard.
     */
    public function ensureDefaultSubscription(): void
    {
        if ($this->subscription()->exists()) {
            return;
        }

        $slug = $this->assignPlanSlug ?? 'free';
        $plan = Plan::query()->where('slug', $slug)->first();

        if (! $plan) {
            throw new \RuntimeException(
                "Tenant '{$this->domain}' could not be assigned a default subscription: " .
                "plan with slug '{$slug}' not found. Run PlansSeeder first."
            );
        }

        Subscription::on('landlord')->create([
            'tenant_id' => $this->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
        ]);
    }

    /**
     * Seed the Spatie permission catalog for this tenant.
     *
     * Creates the `change-plan` permission and the `tenant-admin` role
     * (granted `change-plan`) in the tenant's database. Called by the
     * `created` callback after `ensureDefaultSubscription()`, so every
     * new tenant has the permission catalog ready before any user
     * registers.
     *
     * Uses the custom {@see Role} and {@see Permission} models that
     * are bound to the `tenant` connection, so the queries land in the
     * correct database without flipping the default connection.
     */
    public function seedPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate('change-plan', 'web');
        $role = Role::findOrCreate('tenant-admin', 'web');
        $role->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Get the active subscription for this tenant.
     */
    public function activeSubscription(): ?Subscription
    {
        return $this->subscription?->status === SubscriptionStatus::Active
            ? $this->subscription
            : null;
    }

    /**
     * Check if the tenant has a specific feature enabled.
     *
     * Returns true only if the tenant has an active subscription
     * and the plan includes the specified feature.
     */
    public function hasFeature(string $feature): bool
    {
        $subscription = $this->subscription;

        if (! $subscription || ! $subscription->isActive()) {
            return false;
        }

        return $subscription->hasFeature($feature);
    }

    /**
     * Check if the tenant is on the free tier.
     *
     * A tenant is considered "on the free tier" when it has no
     * subscription, no plan, or its plan slug is exactly 'free'.
     * Any other plan slug (basic, premium, premium-plus, etc.)
     * is treated as a paid tier.
     *
     * The 'free' string is part of the public contract — it is
     * the slug used by the auto-fallback in ensureDefaultSubscription()
     * and by PlansSeeder. The check is slug-based, not id-based,
     * so the answer is stable across seed resets.
     */
    public function isOnFreeTier(): bool
    {
        $subscription = $this->subscription;

        if (! $subscription || ! $subscription->plan) {
            return true;
        }

        return $subscription->plan->slug === 'free';
    }

    /**
     * Assert that the tenants table exists in the landlord database.
     *
     * Without this table the Eloquent INSERT inside Tenant::create() will
     * fail with a "table not found" exception, but only after the creating
     * callback has already done irreversible work (creating the tenant DB
     * and running migrations). This guard fails early with a clear message
     * so the developer knows exactly what to run.
     *
     * @throws \RuntimeException when the tenants table is missing
     */
    protected function assertTenantsTableExists(): void
    {
        if (! Schema::connection('landlord')->hasTable('tenants')) {
            throw new \RuntimeException(
                'The tenants table does not exist in the landlord database. '
                .'Run `php artisan migrate --path=database/migrations/landlord --database=landlord` first.'
            );
        }
    }

    /**
     * Create the physical PostgreSQL database for this tenant.
     *
     * Idempotent: queries the `pg_database` system catalog on the landlord
     * connection before issuing the CREATE. This lets `Tenant::create()` be
     * called repeatedly (e.g. by seeders, by the admin UI, or by retries
     * after a partial provisioning failure) without raising
     * "42P04 database already exists" errors.
     *
     * PostgreSQL does not support `CREATE DATABASE IF NOT EXISTS`, so checking
     * the system catalog is the canonical idempotent pattern for this DDL.
     *
     * The database name comes from the 'database' attribute set during creation.
     * Always runs on the landlord connection (the catalog lives there).
     */
    protected function createDatabase(): void
    {
        $exists = DB::connection('landlord')->select(
            'SELECT 1 FROM pg_database WHERE datname = ?',
            [$this->database]
        );

        if (empty($exists)) {
            DB::unprepared('CREATE DATABASE "'.$this->sanitizeDatabaseName($this->database).'"');
        }
    }

    /**
     * Sanitize a database name for safe use in raw DDL statements.
     *
     * PostgreSQL identifiers allow letters, digits, and underscores,
     * starting with a letter or underscore. Max 63 bytes.
     * Anything else is stripped to prevent SQL injection via DDL.
     */
    private function sanitizeDatabaseName(string $name): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $name);

        return substr($clean, 0, 63);
    }

    /**
     * Point the 'tenant' database connection to a specific database.
     *
     * Changes the config at runtime so that any query using the 'tenant'
     * connection targets the given database. DB::purge() forces Laravel
     * to create a fresh connection with the updated config.
     */
    public static function pointConnectionAt(string $database): void
    {
        config(['database.connections.tenant.database' => $database]);
        DB::purge('tenant');
    }

    /**
     * Drop the cached tenant connection so the next query starts from
     * the configuration defined in config/database.php.
     */
    public static function forgetConnection(): void
    {
        DB::purge('tenant');
    }

    /**
     * Point the 'tenant' database connection to this tenant's database.
     *
     * Convenience wrapper used by the Tenant::creating callback.
     */
    protected function configureTenantConnection(): void
    {
        static::pointConnectionAt($this->database);
    }

    /**
     * Run all pending Laravel migrations on this tenant's database.
     *
     * Uses --database=tenant to target the tenant connection, and --force
     * to bypass the production safety check (required for programmatic calls).
     */
    protected function runMigrations(): void
    {
        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--force' => true,
        ]);
    }
}
