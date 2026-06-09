# Design: Tenant User Management (Phase 1)

## Technical Approach

Add tenant-scoped user CRUD to the existing tenant middleware group. Follow the established pattern from `ResourceController` (controller structure) and `TenantController` (resource controller). Frontend follows the landlord pages pattern with Form components and Wayfinder routes.

## Architecture Decisions

### Decision: Route naming convention

**Choice**: `users.*` prefix (not `tenant.users.*`).
**Alternatives considered**: `tenant.users.*`, `admin.users.*`.
**Rationale**: Tenant routes in `routes/web.php` section 3 don't use a `tenant.` prefix because they're already in the tenant middleware group. The Wayfinder route files follow this pattern (e.g., `/resources` not `/tenant/resources`). Naming would be: `users.index`, `users.create`, `users.store`, etc.

### Decision: Controller location

**Choice**: `App\Http\Controllers\Tenant\UserController`.
**Alternatives considered**: `App\Http\Controllers\UserController` (root namespace).
**Rationale**: Follows the namespace pattern: `Landlord\` for admin controllers, `Resource\` for tenant resource controllers, `Billing\` for billing. The `Tenant\` namespace makes the scope explicit.

### Decision: Pagination approach

**Choice**: Laravel's `->paginate()` with Inertia pagination props.
**Alternatives considered**: Cursor-based pagination, infinite scroll, no pagination.
**Rationale**: Laravel pagination is built-in and Inertia handles it natively. The existing index pages (e.g., `landlord/tenants/index.tsx`) don't paginate yet, but for user lists that could grow, pagination is essential. Inertia's `only` prop can paginate server-side.

### Decision: Sidebar link

**Choice**: Add "Users" nav item visible to all authenticated tenant users.
**Alternatives considered**: Hide behind tenant-admin role check.
**Rationale**: Authorization gates are Phase 2. For now, all authenticated tenant users can access user management. The sidebar already has conditional items (Resources, Analytics) — this follows the same pattern.

### Decision: Form pattern

**Choice**: Shared `UserForm` component in `resources/js/components/tenant/` (field-only wrapper).
**Alternatives considered**: Inline forms in each page, separate create/edit forms.
**Rationale**: Matches `TenantForm`, `PlanForm`, `ResourceForm` pattern. The component receives `mode`, `processing`, `errors`, `defaults` props. Create and edit pages wrap it with `@inertiajs/react` Form.

## Data Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                     REQUEST FLOW                                │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  React Page ──Form──→ Inertia POST/PUT/DELETE                   │
│       │                                                          │
│       ▼                                                          │
│  routes/web.php (section 3: tenant middleware group)            │
│       │                                                          │
│       ▼                                                          │
│  Tenant\UserController                                          │
│       ▼                                                          │
│  User::query() ← UsesTenantConnection                           │
│       │                                                          │
│       ▼                                                          │
│  Tenant PostgreSQL DB                                            │
│       │                                                          │
│       ▼                                                          │
│  Inertia::render() → React Page                                 │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Http/Controllers/Tenant/UserController.php` | Create | Resource controller with 7 methods |
| `routes/web.php` | Modify | Add `Route::resource('users', UserController::class)` to section 3 |
| `resources/js/pages/users/index.tsx` | Create | User list with table, search, pagination |
| `resources/js/pages/users/create.tsx` | Create | Create user form |
| `resources/js/pages/users/edit.tsx` | Create | Edit user form |
| `resources/js/pages/users/show.tsx` | Create | User detail page |
| `resources/js/components/tenant/user-form.tsx` | Create | Shared form component (field wrapper) |
| `resources/js/components/app-sidebar.tsx` | Modify | Add "Users" nav item for tenant-admin |
| `tests/Feature/Tenant/UserControllerTest.php` | Create | CRUD + authorization tests |

## Interfaces / Contracts

### Controller Method Signatures

```php
namespace App\Http\Controllers\Tenant;

class UserController extends Controller
{
    public function index(Request $request): InertiaResponse;
    public function create(): InertiaResponse;
    public function store(Request $request): RedirectResponse;
    public function show(User $user): InertiaResponse;
    public function edit(User $user): InertiaResponse;
    public function update(Request $request, User $user): RedirectResponse;
    public function destroy(Request $request, User $user): RedirectResponse;
}
```

### Validation Rules

```php
// store
'name' => ['required', 'string', 'max:255'],
'email' => ['required', 'email', 'max:255', 'unique:users,email'],
'password' => ['required', 'string', 'min:8', 'confirmed'],

// update
'name' => ['required', 'string', 'max:255'],
'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
'password' => ['nullable', 'string', 'min:8', 'confirmed'],
```

### Response Shape (index)

```php
return Inertia::render('users/index', [
    'users' => User::query()
        ->orderBy('name')
        ->paginate(15)
        ->through(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name'),
        ]),
]);
```

### Sidebar Nav Item

```tsx
// In app-sidebar.tsx, add to mainNavItems for non-admin users:
{
    title: 'Users',
    href: '/users',
    icon: Users,
},
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Feature | CRUD operations (index, create, store, show, edit, update, destroy) | HTTP tests with `actingAs`, assert Inertia renders |
| Feature | Self-deletion prevention | Auth user tries to delete self, assert error |
| Feature | Password handling (blank = keep) | Update user without password, verify password unchanged |
| Feature | Pagination | Create 20+ users, verify paginated response |

### Test Setup Pattern

```php
beforeEach(function () {
    $testDatabase = config('database.connections.landlord.database');
    config(['database.connections.tenant.database' => $testDatabase]);
    DB::purge('tenant');
    
    // Create authenticated user
    $this->user = User::on('tenant')->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
});
```

## Migration / Rollout

No migration required. The `users` table already exists on tenant databases (created by Fortify/Tenancy). The controller queries the existing `users` table through the tenant connection.

## Open Questions

- [ ] Should the index page include search (by name/email)? Yes per proposal.
- [ ] Should we add a "Roles" column to the index table? No — roles are Phase 2.
- [ ] Should the create form auto-assign any role? No per proposal — Phase 2.
