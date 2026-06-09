<?php

namespace Database\Seeders;

use App\Models\Auth\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
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
 * Authorization: every user gets at least the `member` role.
 * The first user per tenant is granted `owner` instead.
 * `syncRoles` replaces whatever roles the user has with the
 * supplied list, which is the safe idempotent form: a re-run
 * leaves the user with exactly the assigned role(s), never duplicates.
 *
 * Ordering: this seeder MUST run AFTER `TenantPermissionsSeeder`
 * (wired in `DatabaseSeeder`), otherwise `syncRoles` will throw
 * "Role `owner` does not exist" because the role row has
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

        Tenant::pointConnectionAt($tenant->database);

        try {
            $user = User::on('tenant')->updateOrCreate(
                ['email' => "{$subdomain}@{$tenant->domain}"],
                [
                    'name' => ucfirst($subdomain),
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            );

            $this->assignUserRole($user);
        } finally {
            Tenant::forgetConnection();
        }
    }

    /**
     * Assign a role to the user based on whether they are the first
     * user in the tenant.
     *
     * - First user → `owner`
     * - All others → `member`
     *
     * `syncRoles` is the idempotent form: it replaces whatever
     * roles the user has with the supplied list. A re-run never
     * produces duplicates.
     */
    protected function assignUserRole(User $user): void
    {
        $isFirstUser = User::on('tenant')->count() <= 1;

        $user->syncRoles([$isFirstUser ? 'owner' : 'member']);
    }

    /**
     * Rewrite the `tenant` connection's `database` value in the
     * in-memory config and drop the cached PDO so the next query
     * opens a fresh connection against the new DB.
     */
}
