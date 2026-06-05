<?php

namespace Database\Seeders;

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
 * just refreshes the password.
 */
class TenantUsersSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $subdomain = "tenant{$tenant->id}";

            $this->pointTenantConnectionAt($tenant->database);

            User::on('tenant')->updateOrCreate(
                ['email' => "{$subdomain}@{$tenant->domain}"],
                [
                    'name' => ucfirst($subdomain),
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            );
        }

        $this->forgetTenantConnection();
    }

    /**
     * Point the `tenant` connection at the named physical database
     * and purge the cached connection so the next query opens a
     * fresh PDO against the new host.
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
