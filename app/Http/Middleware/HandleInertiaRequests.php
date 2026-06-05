<?php

namespace App\Http\Middleware;

use App\Models\Landlord;
use App\Models\Resource;
use App\Models\Tenant;
use Illuminate\Http\Request;
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
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'is_admin' => $request->user() instanceof Landlord,
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
            'is_free_tier' => $current->isOnFreeTier(),
            'has_free_resources' => Resource::query()
                ->active()
                ->where('is_premium', false)
                ->exists(),
            'has_premium_zone' => $current->hasFeature('premium-zone'),
        ];
    }
}
