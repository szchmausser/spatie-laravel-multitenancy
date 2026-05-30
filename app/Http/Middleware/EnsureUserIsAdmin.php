<?php

namespace App\Http\Middleware;

use App\Models\Landlord;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies that the authenticated user is a Landlord (admin platform user).
 *
 * This middleware ensures that only users authenticated through the Landlord
 * model (which uses UsesLandlordConnection) can access admin routes.
 * Tenant users (User model) are rejected even if they somehow reach admin routes.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof Landlord) {
            abort(403, 'Unauthorized: admin access required.');
        }

        return $next($request);
    }
}
