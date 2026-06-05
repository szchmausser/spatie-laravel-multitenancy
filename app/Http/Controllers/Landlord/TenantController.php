<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Tenant management controller for the admin panel.
 *
 * Provides CRUD operations for tenants. The store() method uses the Tenant
 * model's Eloquent lifecycle callback (creating) to automatically provision
 * the tenant database and run migrations. If provisioning fails, the
 * controller handles rollback by dropping the created database.
 *
 * This controller is protected by EnsureUserIsAdmin middleware and never
 * enters tenant context.
 */
class TenantController extends Controller
{
    /**
     * List all tenants.
     */
    public function index()
    {
        $tenants = Tenant::query()
            ->with('subscription.plan')
            ->orderBy('id')
            ->get();

        return Inertia::render('landlord/tenants/index', [
            'tenants' => $tenants,
        ]);
    }

    /**
     * Show the create tenant form.
     */
    public function create()
    {
        return Inertia::render('landlord/tenants/create');
    }

    /**
     * Create a new tenant.
     *
     * The Tenant model's `creating` lifecycle callback handles:
     * 1. Creating the physical database
     * 2. Configuring the tenant connection
     * 3. Running migrations
     *
     * If any step fails, the database is dropped and the exception is re-thrown.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:255', 'unique:tenants,domain'],
            'database' => ['required', 'string', 'max:255', 'unique:tenants,database'],
        ]);

        try {
            Tenant::create($validated);
        } catch (\Exception $e) {
            DB::unprepared('DROP DATABASE IF EXISTS "'.$validated['database'].'"');
            throw $e;
        }

        return redirect()->route('landlord.tenants.index');
    }

    /**
     * Show tenant details.
     */
    public function show(Tenant $tenant)
    {
        $tenant->load('subscription.plan');

        return Inertia::render('landlord/tenants/show', [
            'tenant' => $tenant,
            'subscription' => $tenant->subscription,
            'availablePlans' => Plan::query()->where('is_active', true)->orderBy('price_cents')->get(),
        ]);
    }

    /**
     * Show the edit tenant form.
     */
    public function edit(Tenant $tenant)
    {
        return Inertia::render('landlord/tenants/edit', [
            'tenant' => $tenant,
        ]);
    }

    /**
     * Update an existing tenant.
     *
     * All fields are editable since this is an admin-only operation.
     * The provisioning (DB creation, migrations) is NOT re-run:
     * the tenant database already exists and is left untouched.
     */
    public function update(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:255', 'unique:tenants,domain,'.$tenant->id],
            'database' => ['required', 'string', 'max:255', 'unique:tenants,database,'.$tenant->id],
        ]);

        $tenant->update($validated);

        return redirect()->route('landlord.tenants.index');
    }

    /**
     * Delete a tenant and its database.
     *
     * Drops the physical PostgreSQL database first, then removes
     * the tenant record from the landlord database.
     */
    public function destroy(Tenant $tenant)
    {
        DB::unprepared('DROP DATABASE IF EXISTS "'.$tenant->database.'"');

        $tenant->delete();

        return redirect()->route('landlord.tenants.index');
    }
}
