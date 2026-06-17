<?php

namespace App\Http\Middleware;

use App\Models\Entitlement;
use App\Models\Landlord;
use App\Models\Resource;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $this->resolveUserProp($user),
                'is_admin' => $user instanceof Landlord,
                'unread_notifications_count' => $this->resolveUnreadNotificationsCount($user),
            ],
            // Tenant-scoped data is only meaningful when a request lands on
            // a tenant subdomain. On landlord routes (the SaaS admin panel)
            // `app('currentTenant')` returns null and we share `null` so the
            // client can distinguish "no tenant" from "tenant, free tier".
            'tenant' => $this->resolveTenantData(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Build the `tenant` shared prop from the current tenant, if any.
     *
     * Returns null when the request is being served on the landlord
     * domain (no tenant identified by the DomainTenantFinder). When a
     * tenant is current, returns a small payload the layout uses to
     * decide which nav items to show (e.g. the "Resources"
     * link, which only renders for paid tiers OR for free tiers
     * that have at least one free resource in the catalog).
     *
     * We use `Tenant::current()` rather than `app('currentTenant')`:
     * the former returns `null` cleanly when no tenant is set, while
     * the latter would try to resolve the string as a class and blow
     * up with a BindingResolutionException.
     *
     * The `is_free_tier` flag is computed via Tenant::isOnFreeTier(),
     * which is slug-based, so it survives plan reseeds.
     *
     * The `has_free_resources` flag is `true` when there is at least
     * one active resource with `is_premium = false` in the catalog.
     * It exists so the sidebar can show the "Resources" link
     * to free tenants that actually have something to browse —
     * without it, the link would either be hidden for every free
     * tenant (and the catalog would be useless) or shown for free
     * tenants with an empty catalog (and the page would feel broken).
     *
     * The `has_premium_zone` flag is `true` when the tenant's plan
     * includes the `premium-zone` feature. It exists so the sidebar
     * can show the "Analytics" link to tenants on a plan that grants
     * access to the premium zone (currently only the `premium` plan).
     * Without it, the only way to reach `/premium/analytics` was to
     * type the URL by hand.
     */
    private function resolveTenantData(): ?array
    {
        $current = Tenant::current();

        if (! $current instanceof Tenant) {
            return null;
        }

        return [
            'id' => $current->getKey(),
            'name' => $current->name,
            'domain' => $current->domain,
            'plan_name' => $current->subscription?->plan?->name ?? 'Free',
            'is_free_tier' => $current->isOnFreeTier(),
            'has_free_resources' => Resource::query()
                ->active()
                ->where('is_premium', false)
                ->exists(),
            'has_entitlements' => Entitlement::query()
                ->where('tenant_id', $current->id)
                ->where('user_id', auth()->id())
                ->exists(),
            'has_premium_zone' => $current->hasFeature('premium-zone'),
        ];
    }

    /**
     * Build the `auth.user` shared prop from the current authenticatable.
     *
     * For `User` and `Landlord` instances, returns an explicit array
     * shape that the frontend can rely on (`id`, `name`, `email`,
     * `avatar`, `email_verified_at`, `roles`). This is required by
     * the `tenant-authorization` capability (1.5G.0): the explicit
     * shape is part of the testable contract with `assertInertia`,
     * and the `roles` field is the new addition.
     *
     * For other authenticatable instances (e.g. anonymous classes
     * used by `actingAs()` in some feature tests), fall back to
     * passing the user object as-is. Inertia will serialize its
     * public properties. We don't want to crash a request just
     * because an anonymous test user doesn't expose a `name` field.
     */
    private function resolveUserProp(?Authenticatable $user): mixed
    {
        if ($user === null) {
            return null;
        }

        if (! ($user instanceof User || $user instanceof Landlord)) {
            return $user;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $this->resolveAvatar($user),
            'email_verified_at' => $user->email_verified_at?->toJson(),
            'roles' => $this->resolveRoles($user),
        ];
    }

    /**
     * Resolve the avatar URL for the authenticated user.
     *
     * The User model's `avatar` accessor queries the `media` table on
     * the tenant connection (via `UsesTenantConnection`). In test
     * contexts where the Spatie MediaLibrary migration has not been
     * published to the per-tenant path, the `media` table does not
     * exist on the tenant DB and the query throws. The Landlord model
     * uses the landlord connection, which always has the `media`
     * table, so its avatar resolves cleanly.
     *
     * Defensive approach: probe the schema before triggering the
     * accessor. If the `media` table is missing on the user's
     * connection, return null and let the frontend render a default
     * avatar. This avoids breaking the shared prop on every request
     * that lands on a tenant whose DB has not been migrated with
     * MediaLibrary.
     */
    private function resolveAvatar(Authenticatable $user): ?string
    {
        if (! method_exists($user, 'getFirstMedia')) {
            return null;
        }

        $connection = method_exists($user, 'getConnectionName')
            ? ($user->getConnectionName() ?? config('database.default'))
            : config('database.default');

        try {
            if (! Schema::connection($connection)->hasTable('media')) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        try {
            return $user->avatar;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve the role names for the authenticated user.
     *
     * Only `User` instances have the `HasRoles` trait from Spatie
     * Permissions. The `Landlord` model does not — landlord roles
     * are a separate slice (`1.5G.1-landlord-roles`). For non-User
     * authenticatable instances, the roles array is empty.
     */
    private function resolveRoles(Authenticatable $user): array
    {
        if (! $user instanceof User) {
            return [];
        }

        $connection = method_exists($user, 'getConnectionName')
            ? ($user->getConnectionName() ?? config('database.default'))
            : config('database.default');

        try {
            if (! Schema::connection($connection)->hasTable('roles')) {
                \Log::debug('resolveRoles: roles table missing on connection: '.$connection);

                return [];
            }
        } catch (\Throwable $e) {
            \Log::debug('resolveRoles: schema check failed', ['connection' => $connection, 'error' => $e->getMessage()]);

            return [];
        }

        try {
            $roles = $user->roles?->pluck('name')->toArray() ?? [];
            \Log::debug('resolveRoles result', [
                'connection' => $connection,
                'roles' => $roles,
                'user_id' => $user->id,
                'user_email' => $user->email,
            ]);

            return $roles;
        } catch (\Throwable $e) {
            \Log::debug('resolveRoles: roles query failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Resolve the unread notifications count for the authenticated user.
     *
     * The notifications table may not exist in test contexts where the
     * database migration has not been run. Returns 0 silently.
     */
    private function resolveUnreadNotificationsCount(?Authenticatable $user): int
    {
        if (! $user instanceof User) {
            return 0;
        }

        $connection = method_exists($user, 'getConnectionName')
            ? ($user->getConnectionName() ?? config('database.default'))
            : config('database.default');

        try {
            if (! Schema::connection($connection)->hasTable('notifications')) {
                return 0;
            }
        } catch (\Throwable) {
            return 0;
        }

        try {
            return $user->unreadNotifications()->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
