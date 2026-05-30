<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Inertia\Inertia;

/**
 * Admin panel controller.
 *
 * Handles the landlord/admin panel. Reads data from the landlord
 * database only. This controller is protected by EnsureUserIsAdmin middleware
 * and never enters tenant context (no 'tenant' middleware applied).
 */
class AdminPanelController extends Controller
{
    /**
     * Show the admin panel with tenant overview.
     */
    public function index()
    {
        $totalTenants = Tenant::count();
        $tenants = Tenant::all();

        return Inertia::render('landlord/admin-panel', [
            'totalTenants' => $totalTenants,
            'tenants' => $tenants,
        ]);
    }
}
