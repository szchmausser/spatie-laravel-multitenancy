<?php

namespace Database\Seeders;

use App\Models\Auth\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds one user per tenant in each tenant's own database.
 *
 * Credentials follow a fixed pattern so the landlord can log into
 * any tenant subdomain and verify the setup end-to-end:
 *
 *   - email:    tenant{N}@tenant{N}.spatie-laravel-multitenancy.test
 *               (the local part is the subdomain name, and the email
 *                domain is the full subdomain — unique per tenant)
 *   - name:     Tenant{N}  (matches the subdomain, capitalised for display)
 *   - password: password   (intentionally weak — dev fixture only)
 *
 * Each user is created on the `tenant` connection after pointing
 * it at the tenant's physical database, mirroring what the
 * `creating` callback of the Tenant model does. The local
 * connection cache is purged between tenants so the new value
 * actually takes effect.
 *
 * Idempotent: updateOrCreate on email, so re-running the seeder
 * just refreshes the password and re-applies the role assignment.
 *
 * Authorization: the first (and currently only) user created for
 * a tenant is granted the `tenant-admin` role via `syncRoles`.
 * `syncRoles` replaces whatever roles the user has with the
 * supplied list, which is the safe idempotent form: a re-run
 * leaves the user with exactly one role, never duplicates.
 *
 * Ordering: this seeder MUST run AFTER `TenantPermissionsSeeder`
 * (wired in `DatabaseSeeder`), otherwise `syncRoles` will throw
 * "Role `tenant-admin` does not exist" because the role row has
 * not been inserted into the tenant's `roles` table yet.
 */
class TenantUsersSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $this->forTenant($tenant);
        }
    }

    public function forTenant(Tenant $tenant): void
    {
        $subdomain = "tenant{$tenant->id}";

        $this->pointTenantConnectionAt($tenant->database);

        try {
            $user = User::on('tenant')->updateOrCreate(
                ['email' => "{$subdomain}@{$tenant->domain}"],
                [
                    'name' => ucfirst($subdomain),
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            );

            $this->assignFirstUserRole($user);
        } finally {
            $this->forgetTenantConnection();
        }
    }

    /**
     * Grant the tenant-admin role to the user.
     *
     * `syncRoles` is the idempotent form: it replaces whatever
     * roles the user has with the supplied list. The first user
     * per tenant ends up with exactly one role (`tenant-admin`),
     * and a re-run of the seeder never produces duplicates.
     *
     * The Spatie Role model used by the underlying `syncRoles`
     * call is bound to the `tenant` connection (see
     * {@see Role}), so flipping the global
     * default is not required: the role-lookup query lands on
     * the `tenant` connection automatically. Without that
     * binding, `syncRoles` would query the landlord DB and fail
     * with "no existe la relación «roles»" because the Spatie
     * permission migration is gated to the `tenant` connection.
     */
    protected function assignFirstUserRole(User $user): void
    {
        $user->syncRoles(['tenant-admin']);
    }

    /**
     * Rewrite the `tenant` connection's `database` value in the
     * in-memory config and drop the cached PDO so the next query
     * opens a fresh connection against the new DB.
     */
    protected function pointTenantConnectionAt(string $database): void
    {
        config(['database.connections.tenant.database' => $database]);
        DB::purge('tenant');
    }

    /**
     * Drop the cached tenant connection so a subsequent call (e.g.
     * from a later seeder) starts from the configuration defined
     * in config/database.php, not whatever this seeder last set.
     */
    protected function forgetTenantConnection(): void
    {
        DB::purge('tenant');
    }
}
