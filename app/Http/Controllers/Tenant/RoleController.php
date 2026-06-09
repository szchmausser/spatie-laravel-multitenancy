<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Auth\Permission;
use App\Models\Auth\Role;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Tenant-scoped role catalog controller.
 *
 * Provides read-only views of tenant roles: index (list all roles
 * with permission and user counts) and show (detail view with
 * full permission and user lists).
 *
 * Authorization: granular permissions enforced via Gate::authorize:
 * - roles-list: view role catalog
 * - roles-show: view role details
 */
class RoleController extends Controller
{
    /**
     * Display all roles in the current tenant with counts.
     */
    public function index(): InertiaResponse
    {
        Gate::authorize('roles-list');

        $roles = Role::withCount(['permissions', 'users'])
            ->orderBy('name')
            ->get();

        return Inertia::render('roles/index', [
            'roles' => $roles,
        ]);
    }

    /**
     * Display a specific role with its permissions and users.
     */
    public function show(Role $role): InertiaResponse
    {
        Gate::authorize('roles-show');

        $role->load(['permissions', 'users']);

        // Pass all available permissions so the frontend can show
        // a complete checklist, not just the role's assigned ones.
        $allPermissions = Permission::on('tenant')
            ->orderBy('name')
            ->pluck('name')
            ->toArray();

        return Inertia::render('roles/show', [
            'role' => $role,
            'allPermissions' => $allPermissions,
        ]);
    }
}
