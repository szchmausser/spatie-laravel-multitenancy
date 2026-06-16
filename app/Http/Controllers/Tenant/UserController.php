<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Auth\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tenant-scoped user management controller.
 *
 * Provides CRUD operations for users within the current tenant,
 * plus role assignment/removal actions.
 * All queries are scoped to the tenant's database via the
 * UsesTenantConnection trait on the User model.
 *
 * Authorization: granular permissions enforced via Gate::authorize:
 * - users-list: view user list
 * - users-show: view user details
 * - users-create: create new users
 * - users-update: edit existing users
 * - users-delete: remove users
 * - users-manage-roles: assign/remove roles
 */
class UserController extends Controller
{
    /**
     * Display a paginated list of users in the current tenant.
     */
    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('users-list');

        $query = User::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $users = $query->with('roles')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('settings/users/index', [
            'users' => $users,
        ]);
    }

    /**
     * Show the form for creating a new user.
     */
    public function create(): InertiaResponse
    {
        Gate::authorize('users-create');

        return Inertia::render('settings/users/create');
    }

    /**
     * Store a newly created user in the current tenant.
     *
     * Every user gets at least the `member` role. The first user
     * created in a tenant is automatically assigned `owner` instead.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('users-create');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($validated) {
            $user = User::create($validated);

            // First user in tenant gets owner, all others get member
            $role = User::count() === 1 ? 'owner' : 'member';
            $user->assignRole($role);

            return $user;
        });

        return redirect()->route('settings.users.show', $user);
    }

    /**
     * Display the specified user.
     */
    public function show(User $user): InertiaResponse
    {
        Gate::authorize('users-show');

        $user->load('roles');

        // Pass all available roles so the frontend can render
        // the role assignment UI.
        $allRoles = Role::on('tenant')
            ->orderBy('name')
            ->get()
            ->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
            ]);

        // Pass current user info for role change authorization
        $currentUser = User::on('tenant')->find(auth()->id());
        $currentUser->load('roles');

        return Inertia::render('settings/users/show', [
            'user' => $user,
            'allRoles' => $allRoles,
            'currentUser' => [
                'id' => $currentUser->id,
                'roles' => $currentUser->roles->map(fn ($r) => ['name' => $r->name]),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified user.
     */
    public function edit(User $user): InertiaResponse
    {
        Gate::authorize('users-update');

        return Inertia::render('settings/users/edit', [
            'user' => $user,
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('users-update');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        if (! empty($validated['password'])) {
            $user->update($validated);
        } else {
            unset($validated['password']);
            $user->update($validated);
        }

        return redirect()->route('settings.users.show', $user);
    }

    /**
     * Remove the specified user.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('users-delete');

        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'You cannot delete your own account.']);
        }

        // Skip Spatie HasRoles boot events (role detach) during delete.
        User::withoutEvents(fn () => $user->delete());

        return redirect()->route('settings.users.index');
    }

    /**
     * Assign a role to a user, replacing all existing roles.
     *
     * Authorization: owner and tenant-admin can manage roles.
     * Constraints:
     * - Users cannot change their own role (self-protection).
     * - Owner can change anyone except another owner.
     * - Tenant-admin can change members only (not owner, not another tenant-admin).
     * - Owner role is immutable via UI — requires DB access to change.
     */
    public function assignRole(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('users-manage-roles');

        $currentUser = User::on('tenant')->find(auth()->id());

        // Self-protection: cannot change your own role
        if ($user->id === $currentUser->id) {
            return back()->withErrors(['role' => 'You cannot change your own role.']);
        }

        // Check if target user has a role that cannot be changed
        if ($user->hasRole('owner')) {
            return back()->withErrors(['role' => 'The owner role cannot be changed via the UI.']);
        }

        if ($user->hasRole('tenant-admin') && $currentUser->hasRole('tenant-admin')) {
            return back()->withErrors(['role' => 'You cannot change the role of another tenant-admin.']);
        }

        $roleName = $request->input('role');

        if (! $roleName || ! is_string($roleName)) {
            return back()->withErrors(['role' => 'The role field is required.']);
        }

        // Cannot assign owner role via UI
        if ($roleName === 'owner') {
            return back()->withErrors(['role' => 'The owner role cannot be assigned via the UI.']);
        }

        // Check role exists in the tenant database directly
        // (validation rule 'exists:roles,name' uses the default connection)
        $role = Role::on('tenant')->where('name', $roleName)->first();
        if (! $role) {
            return back()->withErrors(['role' => 'The selected role does not exist.']);
        }

        // Replace all roles with the new one
        $user->syncRoles([$roleName]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('settings.users.show', $user);
    }

    /**
     * Remove a role from a user.
     *
     * Authorization: owner and tenant-admin can manage roles.
     * Constraints:
     * - Owner role is immutable via UI.
     * - Self-protection: users cannot remove their own admin roles.
     * - Tenant-admin cannot remove another tenant-admin's role.
     */
    public function removeRole(User $user, Role $role): RedirectResponse
    {
        Gate::authorize('users-manage-roles');

        $currentUser = User::on('tenant')->find(auth()->id());

        // Owner role is immutable via UI
        if ($role->name === 'owner') {
            return back()->withErrors(['role' => 'The owner role cannot be removed via the UI.']);
        }

        // Self-protection: cannot remove own admin roles
        if ($user->id === $currentUser->id && in_array($role->name, ['owner', 'tenant-admin'])) {
            return back()->withErrors(['role' => 'You cannot remove your own admin role.']);
        }

        // Tenant-admin cannot remove another tenant-admin's role
        if ($user->hasRole('tenant-admin') && $currentUser->hasRole('tenant-admin')) {
            return back()->withErrors(['role' => 'You cannot remove the role of another tenant-admin.']);
        }

        $user->removeRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('settings.users.show', $user);
    }
}
