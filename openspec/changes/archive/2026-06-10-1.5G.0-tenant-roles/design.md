# Design: Phase 2 — Tenant Roles & Permissions Management

**Change ID**: `1.5G.0-tenant-roles`
**Delta**: `design.md`
**Status**: DRAFT

## 1. Architecture Decisions

### 1.1 Controller Architecture: Separate `RoleController` + Extended `UserController`

**Decision**: Create a new `RoleController` for role index/show. Add `assignRole` and `removeRole` actions to the existing `UserController`.

**Rationale**:
- Roles are a read-only catalog (index + show). A dedicated controller keeps the concern separate from user CRUD.
- Role assignment/removal is fundamentally a user action ("assign role TO user"), so those endpoints belong on `UserController` as custom actions.
- This avoids route nesting complexity (`users/{user}/roles/{role}`) while keeping semantic clarity.

### 1.2 Route Structure

**Decision**: Flat route structure under the existing tenant middleware group.

```php
// routes/web.php — inside the tenant middleware group

// Role catalog (read-only)
Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
Route::get('roles/{role}', [RoleController::class, 'show'])->name('roles.show');

// Role assignment/removal on users (custom actions)
Route::post('users/{user}/roles', [UserController::class, 'assignRole'])->name('users.assignRole');
Route::delete('users/{user}/roles/{role}', [UserController::class, 'removeRole'])->name('users.removeRole');
```

**Why not nested**: `roles/{role}/users` would show users-per-role, but the spec asks for this on the role show page. The controller loads the relationship there. No need for a separate endpoint.

### 1.3 Database Changes

**Decision**: No new migrations. The existing Spatie permission tables (`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`) already support everything needed.

The only change is to the seeder: expand `TenantPermissionsSeeder::PERMISSIONS` and `ROLES_WITH_PERMISSIONS` constants, and update `TenantUsersSeeder` to assign `owner` instead of `tenant-admin` to the first user.

### 1.4 Frontend Pages

**Decision**: Two new pages (`roles/index.tsx`, `roles/show.tsx`) and modifications to two existing pages (`users/index.tsx`, `users/show.tsx`).

**Role index page**: Table with columns — Role Name, Permissions (count), Users (count). Each row links to role show page.

**Role show page**: Two sections — (1) Permissions list with checkmarks, (2) Users table showing all users with that role. Includes back navigation to roles index.

**User index page**: Add a "Role" column showing the user's primary role as a badge.

**User show page**: Add a "Roles" section below the existing user details card, showing all assigned roles as badges.

### 1.5 Authorization Strategy

**Decision**: Gate-based authorization using Spatie permissions, checked inside controllers (not middleware).

**Gates**:
- `manage-users` permission: Required for `assignRole`, `removeRole`, role index, role show.
- Self-protection: Server-side check in `removeRole` — reject if target user is the authenticated user AND the role is `owner` or `tenant-admin`.
- Owner immutable: Server-side check in `removeRole` — reject if the role being removed is `owner` (regardless of who is performing the action).

**Why controller-level, not middleware**: Spatie permission checks require the user model with tenant-scoped roles. Middleware runs before the controller, but the gate logic needs the resolved user model. Putting it in the controller keeps it testable and explicit.

### 1.6 First-User Auto-Assignment

**Decision**: Check `User::count() === 0` inside `UserController::store` within a DB transaction.

```php
public function store(Request $request): RedirectResponse
{
    $validated = $request->validate([...]);

    $user = DB::transaction(function () use ($validated) {
        $user = User::create($validated);

        // First user in tenant gets owner role
        if (User::count() === 1) {
            $user->assignRole('owner');
        }

        return $user;
    });

    return redirect()->route('users.show', $user);
}
```

**Race condition mitigation**: `DB::transaction` + `count() === 1` is safe because:
- The tenant database is single-tenant (no concurrent provisioning in production).
- In tests, `createQuietly()` avoids events, so the count is deterministic.
- If two users are created simultaneously, the second `count()` will be > 1, so no owner is assigned (acceptable — manual assignment needed).

### 1.7 Seeder Changes

**TenantPermissionsSeeder**:

```php
public const PERMISSIONS = [
    'manage-users',
    'change-plan',
];

public const ROLES_WITH_PERMISSIONS = [
    'owner' => [
        'manage-users',
        'change-plan',
    ],
    'tenant-admin' => [
        'manage-users',
        'change-plan',
    ],
    'member' => [],
];
```

**TenantUsersSeeder**: Change `syncRoles(['tenant-admin'])` to `syncRoles(['owner'])` for the first user.

## 2. Implementation Plan

### 2.1 Files to Create

| File | Purpose |
|------|---------|
| `app/Http/Controllers/Tenant/RoleController.php` | Role index + show with authorization |
| `resources/js/pages/roles/index.tsx` | Role listing page |
| `resources/js/pages/roles/show.tsx` | Role detail page |
| `tests/Feature/Tenant/RoleManagementTest.php` | Role CRUD + assignment tests |
| `tests/Feature/Tenant/RoleAuthorizationTest.php` | Authorization gate tests |

### 2.2 Files to Modify

| File | Changes |
|------|---------|
| `database/seeders/TenantPermissionsSeeder.php` | Add `manage-users` perm, `owner`/`member` roles |
| `database/seeders/TenantUsersSeeder.php` | Change first user role from `tenant-admin` to `owner` |
| `app/Http/Controllers/Tenant/UserController.php` | Add `assignRole`/`removeRole` actions, auto-owner in `store` |
| `routes/web.php` | Add role routes + custom user role actions |
| `resources/js/pages/users/index.tsx` | Add role column with badge |
| `resources/js/pages/users/show.tsx` | Add roles section |

### 2.3 Implementation Order

1. **Seeder expansion** — Foundation. Everything depends on roles existing.
2. **RoleController** — Read-only role catalog. Testable immediately.
3. **UserController modifications** — `assignRole`/`removeRole` + first-user auto-owner.
4. **Routes** — Wire up new endpoints.
5. **Frontend: roles pages** — New pages for role index/show.
6. **Frontend: user pages** — Add role badges to existing pages.
7. **Tests** — Role management + authorization tests.

## 3. Detailed Component Design

### 3.1 RoleController

```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Auth\Role;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class RoleController extends Controller
{
    public function index(): InertiaResponse
    {
        Gate::authorize('manage-users');

        $roles = Role::withCount(['permissions', 'users'])
            ->orderBy('name')
            ->get();

        return Inertia::render('roles/index', [
            'roles' => $roles,
        ]);
    }

    public function show(Role $role): InertiaResponse
    {
        Gate::authorize('manage-users');

        $role->load(['permissions', 'users']);

        return Inertia::render('roles/show', [
            'role' => $role,
        ]);
    }
}
```

### 3.2 UserController — New Actions

```php
/**
 * Assign a role to a user.
 */
public function assignRole(Request $request, User $user): RedirectResponse
{
    Gate::authorize('manage-users');

    $validated = $request->validate([
        'role' => ['required', 'string', 'exists:roles,name'],
    ]);

    $user->assignRole($validated['role']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return redirect()->route('users.show', $user);
}

/**
 * Remove a role from a user.
 */
public function removeRole(User $user, Role $role): RedirectResponse
{
    Gate::authorize('manage-users');

    // Owner role is immutable — cannot be removed from anyone
    if ($role->name === 'owner') {
        return back()->withErrors(['role' => 'The owner role cannot be removed.']);
    }

    // Self-protection: cannot remove own admin roles
    if ($user->id === auth()->id() && in_array($role->name, ['owner', 'tenant-admin'])) {
        return back()->withErrors(['role' => 'You cannot remove your own admin role.']);
    }

    $user->removeRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return redirect()->route('users.show', $user);
}
```

### 3.3 Seeder Expansion

**TenantPermissionsSeeder** — Add `manage-users` permission, `owner` and `member` roles:

```php
public const PERMISSIONS = [
    'manage-users',
    'change-plan',
];

public const ROLES_WITH_PERMISSIONS = [
    'owner' => [
        'manage-users',
        'change-plan',
    ],
    'tenant-admin' => [
        'manage-users',
        'change-plan',
    ],
    'member' => [],
];
```

**TenantUsersSeeder** — Change first user role:

```php
protected function assignFirstUserRole(User $user): void
{
    $user->syncRoles(['owner']);
}
```

### 3.4 Frontend — Roles Index Page

```tsx
// resources/js/pages/roles/index.tsx
import { Link } from '@inertiajs/react';
import { Shield, Users } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { index as rolesIndex, show } from '@/routes/roles';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Roles', href: '/roles' },
];

type RoleRow = {
    id: number;
    name: string;
    permissions_count: number;
    users_count: number;
};

export default function RolesIndex({
    roles,
}: {
    roles: RoleRow[];
}) {
    return (
        <div className="p-6">
            <div className="mb-6">
                <h1 className="text-2xl font-bold">Roles</h1>
                <p className="mt-1 text-sm text-muted-foreground">
                    View roles and their permissions in this tenant.
                </p>
            </div>

            <div className="border rounded-lg divide-y">
                {roles.length === 0 ? (
                    <p className="p-4 text-gray-500">No roles found.</p>
                ) : (
                    roles.map((role) => (
                        <div
                            key={role.id}
                            className="p-4 grid gap-3 md:grid-cols-[1fr_auto] md:items-center"
                        >
                            <div className="space-y-1">
                                <p className="font-medium flex items-center gap-2">
                                    <Shield className="h-4 w-4 text-muted-foreground" />
                                    {role.name}
                                </p>
                                <div className="flex gap-2 text-sm text-muted-foreground">
                                    <span>{role.permissions_count} permissions</span>
                                    <span>·</span>
                                    <span>{role.users_count} users</span>
                                </div>
                            </div>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={show(role.id).url}>
                                    View Details
                                </Link>
                            </Button>
                        </div>
                    ))
                )}
            </div>
        </div>
    );
}

RolesIndex.layout = {
    breadcrumbs,
};
```

### 3.5 Frontend — Roles Show Page

```tsx
// resources/js/pages/roles/show.tsx
import { Link } from '@inertiajs/react';
import { ArrowLeft, Check, Shield, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { index } from '@/routes/roles';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Roles', href: '/roles' },
    { title: 'Details', href: '#' },
];

const ALL_PERMISSIONS = ['manage-users', 'change-plan'];

export default function RolesShow({
    role,
}: {
    role: {
        id: number;
        name: string;
        permissions: Array<{ id: number; name: string }>;
        users: Array<{ id: number; name: string; email: string }>;
    };
}) {
    const rolePermissions = role.permissions.map((p) => p.name);

    return (
        <div className="p-6 space-y-4">
            <div className="flex justify-between items-center mb-6">
                <h1 className="text-2xl font-bold flex items-center gap-2">
                    <Shield className="h-6 w-6" />
                    {role.name}
                </h1>
                <Button variant="outline" asChild>
                    <Link href={index().url}>
                        <ArrowLeft className="h-4 w-4" />
                        Back to Roles
                    </Link>
                </Button>
            </div>

            {/* Permissions */}
            <Card>
                <CardHeader>
                    <CardTitle>Permissions</CardTitle>
                    <CardDescription>
                        Permissions granted to the {role.name} role.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="space-y-2">
                        {ALL_PERMISSIONS.map((perm) => (
                            <div key={perm} className="flex items-center gap-2">
                                {rolePermissions.includes(perm) ? (
                                    <Check className="h-4 w-4 text-green-600" />
                                ) : (
                                    <X className="h-4 w-4 text-gray-400" />
                                )}
                                <span className={rolePermissions.includes(perm) ? '' : 'text-muted-foreground'}>
                                    {perm}
                                </span>
                            </div>
                        ))}
                    </div>
                </CardContent>
            </Card>

            {/* Users */}
            <Card>
                <CardHeader>
                    <CardTitle>Users ({role.users.length})</CardTitle>
                    <CardDescription>
                        Users assigned to the {role.name} role.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {role.users.length === 0 ? (
                        <p className="text-muted-foreground">No users with this role.</p>
                    ) : (
                        <div className="space-y-2">
                            {role.users.map((user) => (
                                <div key={user.id} className="flex items-center justify-between p-2 rounded border">
                                    <div>
                                        <p className="font-medium">{user.name}</p>
                                        <p className="text-sm text-muted-foreground">{user.email}</p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

RolesShow.layout = {
    breadcrumbs,
};
```

### 3.6 Frontend — User Index (Modified)

Add a role column to the existing user index page:

```tsx
// In the user row, add after the email:
<div className="flex items-center gap-2">
    <Badge variant="secondary">
        {user.primary_role ?? 'No role'}
    </Badge>
</div>
```

The controller must eager-load roles:

```php
$users = $query->with('roles')
    ->orderBy('name')
    ->paginate(15)
    ->withQueryString();
```

And the `UserRow` type extends to include roles:

```tsx
type UserRow = {
    id: number;
    name: string;
    email: string;
    roles: Array<{ id: number; name: string }>;
};
```

### 3.7 Frontend — User Show (Modified)

Add a roles section below the existing user details card:

```tsx
{/* Roles Section */}
<Card>
    <CardHeader>
        <CardTitle>Roles</CardTitle>
        <CardDescription>
            Roles assigned to this user in the current tenant.
        </CardDescription>
    </CardHeader>
    <CardContent>
        {user.roles.length === 0 ? (
            <p className="text-muted-foreground">No roles assigned.</p>
        ) : (
            <div className="flex flex-wrap gap-2">
                {user.roles.map((role) => (
                    <Badge key={role.id} variant="default">
                        <Shield className="h-3 w-3" />
                        {role.name}
                    </Badge>
                ))}
            </div>
        )}
    </CardContent>
</Card>
```

The controller must eager-load roles:

```php
public function show(User $user): InertiaResponse
{
    $user->load('roles');

    return Inertia::render('users/show', [
        'user' => $user,
    ]);
}
```

## 4. Authorization Rules Summary

| Action | Who Can Do It | Constraint |
|--------|---------------|------------|
| View role index | `owner`, `tenant-admin` | — |
| View role detail | `owner`, `tenant-admin` | — |
| Assign role to user | `owner`, `tenant-admin` | Target user must exist in same tenant |
| Remove role from user | `owner`, `tenant-admin` | Cannot remove `owner` from anyone; cannot remove own `owner`/`tenant-admin` |
| Create first user | Any authenticated user | Gets `owner` automatically (zero users → owner) |
| Create subsequent users | Any authenticated user | No default role assigned |

## 5. Test Strategy

### 5.1 RoleManagementTest

| Test | Description |
|------|-------------|
| `role_index_requires_manage_users_permission` | Member gets 403 |
| `role_index_shows_all_roles` | Owner sees 3 roles with counts |
| `role_show_requires_manage_users_permission` | Member gets 403 |
| `role_show_displays_permissions_and_users` | Owner sees perm list + user list |
| `role_show_returns_404_for_nonexistent` | Invalid ID returns 404 |
| `owner_can_assign_role_to_user` | Owner assigns member → success |
| `tenant_admin_can_assign_role_to_user` | Tenant-admin assigns member → success |
| `member_cannot_assign_role` | Member gets 403 |
| `owner_can_remove_role_from_user` | Owner removes member role → success |
| `tenant_admin_can_remove_role_from_user` | Tenant-admin removes member role → success |
| `member_cannot_remove_role` | Member gets 403 |
| `owner_role_cannot_be_removed_from_anyone` | Attempt to remove owner → error |
| `user_cannot_remove_own_owner_role` | Self-protection → error |
| `user_cannot_remove_own_tenant_admin_role` | Self-protection → error |
| `first_user_gets_owner_role` | Store with zero users → owner assigned |
| `subsequent_user_gets_no_role` | Store with existing users → no role |
| `permission_cache_cleared_on_role_mutation` | Assign role → can() reflects immediately |

### 5.2 Test Setup Pattern

Follow the existing `UserControllerTest` pattern:

```php
beforeEach(function () {
    $testDatabase = config('database.connections.landlord.database');
    config(['database.connections.tenant.database' => $testDatabase]);
    DB::purge('tenant');

    $this->withoutMiddleware([
        VerifyCsrfToken::class,
        NeedsTenant::class,
        EnsureValidTenantSession::class,
    ]);

    // Seed roles for the test tenant
    (new TenantPermissionsSeeder)->runForCurrentConnection();
});
```

## 6. Risk Mitigations

| Risk | Mitigation |
|------|------------|
| Stale permission cache | Call `forgetCachedPermissions()` after every role mutation |
| Self-protection bypass | Server-side check in `removeRole`, not just UI hiding |
| Owner role removal | Hard check: reject if role name is `owner` regardless of caller |
| Cross-tenant access | User model uses `UsesTenantConnection`; role queries scoped to tenant DB |
| Race condition on first-user | `DB::transaction` + `count() === 1` is safe for single-tenant provisioning |

## 7. Rollback

If this phase needs to be reverted:

1. Revert `TenantPermissionsSeeder.php` (remove `manage-users`, `owner`, `member`)
2. Revert `TenantUsersSeeder.php` (restore `tenant-admin` for first user)
3. Remove `RoleController.php`
4. Remove role routes from `web.php`
5. Revert `UserController.php` (remove `assignRole`/`removeRole`, remove auto-owner from `store`)
6. Remove `resources/js/pages/roles/` directory
7. Revert `users/index.tsx` and `users/show.tsx` (remove role columns)
8. Remove test files
