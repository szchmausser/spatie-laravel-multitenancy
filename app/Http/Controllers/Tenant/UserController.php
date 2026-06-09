<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Tenant-scoped user management controller.
 *
 * Provides CRUD operations for users within the current tenant.
 * All queries are scoped to the tenant's database via the
 * UsesTenantConnection trait on the User model.
 *
 * Phase 1: No authorization gates — all authenticated tenant
 * users can access user management.
 */
class UserController extends Controller
{
    /**
     * Display a paginated list of users in the current tenant.
     */
    public function index(Request $request): InertiaResponse
    {
        $query = User::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $users = $query->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('users/index', [
            'users' => $users,
        ]);
    }

    /**
     * Show the form for creating a new user.
     */
    public function create(): InertiaResponse
    {
        return Inertia::render('users/create');
    }

    /**
     * Store a newly created user in the current tenant.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        User::create($validated);

        return redirect()->route('users.show', User::where('email', $validated['email'])->first());
    }

    /**
     * Display the specified user.
     */
    public function show(User $user): InertiaResponse
    {
        return Inertia::render('users/show', [
            'user' => $user,
        ]);
    }

    /**
     * Show the form for editing the specified user.
     */
    public function edit(User $user): InertiaResponse
    {
        return Inertia::render('users/edit', [
            'user' => $user,
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
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

        return redirect()->route('users.show', $user);
    }

    /**
     * Remove the specified user.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'You cannot delete your own account.']);
        }

        // Skip Spatie HasRoles boot events (role detach) during delete.
        // Phase 1 has no role management; the model_has_roles pivot may
        // not exist on the tenant connection in all environments.
        User::withoutEvents(fn () => $user->delete());

        return redirect()->route('users.index');
    }
}
