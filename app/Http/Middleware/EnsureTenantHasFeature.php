<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to verify the current tenant has a specific feature enabled.
 *
 * Aborts with 403 if:
 * - No tenant is resolved for the current request
 * - The tenant does not have the specified feature enabled
 */
class EnsureTenantHasFeature
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $tenant = Tenant::current();

        if (! $tenant) {
            abort(403, 'Tenant context required.');
        }

        if (! $tenant->hasFeature($feature)) {
            abort(403, 'Your current plan does not include this feature.');
        }

        return $next($request);
    }
}
