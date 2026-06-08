<?php

namespace App\Http\Controllers\Resource;

use App\Enums\EntitlementGrantVia;
use App\Http\Controllers\Controller;
use App\Models\Entitlement;
use App\Models\Resource;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tenant-side resource catalog and download endpoints.
 *
 * Access control is per-resource, NOT per-tenant, so the controller
 * gates inside each method rather than at the route:
 *
 *   - Free resources (is_premium = false) are visible to every
 *     authenticated tenant, including free tier.
 *   - Premium resources are reachable when the tenant's plan has
 *     the `premium-content` feature, or when the user has an
 *     explicit (non-expired) Entitlement row.
 *
 * Phase 1.5F: the catalog and show page show EVERY active resource
 * to every authenticated tenant. The `can_download` flag in the
 * response payload drives the UI button (Download vs Buy). The
 * `request()` endpoint is open to every authenticated tenant; the
 * Buy dialog posts there and the controller creates a
 * purchase-flavoured entitlement. The download endpoint then 403s
 * for tenants without an entitlement or premium-content plan
 * feature, but the slug is no longer secret — we want the user to
 * be able to see premium entries and buy them.
 *
 * The sidebar stays in sync with the catalog via the
 * `has_free_resources` flag on the shared `tenant` prop, see
 * HandleInertiaRequests. The Buy flow is a Phase 1.5F placeholder
 * for the Phase 2 payment gateway — the comment marker
 * "// Phase 2: replace this simulated purchase" sits in the
 * frontend `BuyResourceDialog` component.
 */
class ResourceController extends Controller
{
    /**
     * Display the catalog of active resources.
     *
     * The page lists every active resource and decorates each
     * entry with the current user's access state (`can_download`)
     * so the React side can render the right button (Download,
     * Buy) without re-deriving the rules client-side.
     *
     * Phase 1.5F: free-tier tenants see the same catalog as paid
     * tenants. Premium entries show with `can_download = false`
     * (no entitlement, plan lacks premium-content), which the UI
     * renders as a "Buy" button.
     */
    public function index(Request $request): InertiaResponse
    {
        $tenant = Tenant::current();
        $user = $request->user();

        $resources = Resource::query()
            ->active()
            ->orderBy('is_premium') // free first, premium last
            ->orderBy('name')
            ->get()
            ->map(fn (Resource $r): array => $this->serializeResource($r, $tenant, $user))
            ->all();

        return Inertia::render('resources/index', [
            'resources' => $resources,
        ]);
    }

    /**
     * Display the detail page for a single resource.
     *
     * Phase 1.5F: every authenticated tenant can view the show
     * page for any active resource, including premium slugs. The
     * `can_download` flag on the response drives the action
     * button (Download vs Buy).
     */
    public function show(Request $request, string $slug): InertiaResponse
    {
        $tenant = Tenant::current();
        $user = $request->user();

        $resource = Resource::query()
            ->active()
            ->where('slug', $slug)
            ->firstOrFail();

        return Inertia::render('resources/show', [
            'resource' => $this->serializeResource($resource, $tenant, $user),
        ]);
    }

    /**
     * Simulated "buy" endpoint: creates a purchase entitlement
     * for the (tenant, user, resource) triple.
     *
     * Phase 1.5F: this endpoint is open to every authenticated
     * tenant. The Buy dialog posts here and the controller creates
     * a `granted_via = 'purchase'` entitlement on the fly. Phase 2
     * will replace this with a real payment + webhook confirmation
     * — the comment marker for the swap lives in the
     * `BuyResourceDialog` React component (frontend).
     *
     * `updateOrCreate` keeps double-clicks idempotent; the
     * UNIQUE(tenant_id, user_id, resource_id) constraint is the
     * second layer of defence. The flash message gives the UI
     * something to display after the dialog closes.
     */
    public function request(Request $request, string $slug): RedirectResponse
    {
        $resource = Resource::query()
            ->active()
            ->where('slug', $slug)
            ->firstOrFail();

        $tenant = Tenant::current();
        $user = $request->user();

        if (! $tenant || ! $user) {
            abort(403, 'Tenant and authenticated user are required.');
        }

        Entitlement::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'user_id' => $user->getKey(),
                'resource_id' => $resource->id,
            ],
            [
                'granted_via' => EntitlementGrantVia::Purchase,
                'granted_at' => now(),
                'expires_at' => null,
            ],
        );

        return back()->with('success', "Access granted to {$resource->name}.");
    }

    /**
     * Stream the resource's file as a download, gated by the
     * access rules in `userCanAccess()`. The 404 from `firstOrFail`
     * still protects against unknown slugs; a 403 (not a 404)
     * protects against known slugs the user cannot download.
     */
    public function download(Request $request, string $slug): StreamedResponse
    {
        $tenant = Tenant::current();
        $user = $request->user();

        $resource = Resource::query()
            ->active()
            ->where('slug', $slug)
            ->firstOrFail();

        if (! $this->userCanAccess($tenant, $user, $resource)) {
            abort(403, 'You do not have access to this resource.');
        }

        if (! Storage::disk($this->disk())->exists($resource->file_path)) {
            abort(404, 'The file for this resource is missing.');
        }

        return Storage::disk($this->disk())->download(
            $resource->file_path,
            $resource->slug.'.'.$this->extensionFromMime($resource->mime_type),
        );
    }

    /**
     * Whether the given user can access the given resource.
     *
     * Rules (evaluated in order, short-circuit on first true):
     *  1. The resource is not premium — anyone authenticated can
     *     download it.
     *  2. The user's tenant's plan includes the `premium-content`
     *     feature — the plan grants blanket access.
     *  3. The user has an explicit (non-expired) entitlement row
     *     for the resource — the per-resource purchase grants
     *     access.
     *
     * Anything else (no tenant, no user, expired entitlement) is
     * denied.
     */
    private function userCanAccess(?Tenant $tenant, mixed $user, Resource $resource): bool
    {
        if (! $resource->is_premium) {
            return true;
        }

        if ($tenant && $tenant->hasFeature('premium-content')) {
            return true;
        }

        return $this->userHasExplicitEntitlement($tenant, $user, $resource);
    }

    /**
     * Whether the user has a non-expired entitlement row for the
     * resource. Used both for the "has explicit grant" UI signal
     * and as the third rule in userCanAccess.
     */
    private function userHasExplicitEntitlement(?Tenant $tenant, mixed $user, Resource $resource): bool
    {
        if (! $tenant || ! $user) {
            return false;
        }

        return Entitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->getKey())
            ->where('resource_id', $resource->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    /**
     * Serialize a Resource model for the Inertia response.
     *
     * Single source of truth for the resource array shape.
     * Used by both index() (via map) and show() (single item).
     */
    private function serializeResource(Resource $r, ?Tenant $tenant, mixed $user): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'slug' => $r->slug,
            'description' => $r->description,
            'is_premium' => $r->is_premium,
            'price_cents' => $r->price_cents,
            'file_size_bytes' => $r->file_size_bytes,
            'formatted_file_size' => $r->formattedFileSize(),
            'mime_type' => $r->mime_type,
            'can_download' => $this->userCanAccess($tenant, $user, $r),
            'has_explicit_entitlement' => $this->userHasExplicitEntitlement($tenant, $user, $r),
        ];
    }

    /**
     * The storage disk where resource files live. Files are
     * stored on the landlord's default disk (private), since the
     * catalog is global and never per-tenant.
     */
    private function disk(): string
    {
        return 'local';
    }

    /**
     * Derive a file extension from a stored mime_type. Used so the
     * download response picks a sane filename when the slug does
     * not include an extension.
     */
    private function extensionFromMime(?string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'application/zip' => 'zip',
            'application/x-tar', 'application/gzip' => 'tar.gz',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'audio/ogg' => 'ogg',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'application/json' => 'json',
            'application/octet-stream' => 'bin',
            default => 'bin',
        };
    }
}
