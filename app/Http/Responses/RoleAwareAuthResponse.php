<?php

namespace App\Http\Responses;

use App\Models\Landlord;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\RegisterResponse;

/**
 * Post-authentication redirect that respects the user role.
 *
 * - Landlords (admin users) → /admin (Panel view, `landlord/admin-panel`).
 * - Tenants (regular users) → /dashboard (Dashboard view, `dashboard`).
 *
 * Uses `redirect()->intended()` so that a user who was trying to reach a
 * protected page before being sent to /login still goes there, as long as
 * that page is reachable for their role. If no intended URL is set, the
 * role-appropriate home is used as the fallback.
 *
 * Implements both `LoginResponse` and `RegisterResponse` because the
 * logic is identical for both flows. This is the canonical pattern for
 * customizing Fortify's post-auth redirect without touching the
 * `home` config (which is still consumed by `RedirectIfAuthenticated`).
 */
class RoleAwareAuthResponse implements LoginResponse, RegisterResponse
{
    public function toResponse($request)
    {
        return redirect()->intended(
            $request->user() instanceof Landlord ? '/admin' : '/dashboard'
        );
    }
}
