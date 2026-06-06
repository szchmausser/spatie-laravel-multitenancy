<?php

namespace App\Models\Auth;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Spatie Permission `Role` model bound to the `tenant` connection.
 *
 * Why this subclass exists:
 *
 * The Spatie permission migration
 * (`database/migrations/2026_06_06_132424_create_permission_tables.php`)
 * is gated to the `tenant` connection — the `up()` method returns
 * early when the active connection is anything else, so the
 * permission tables exist ONLY in per-tenant databases, never in
 * the landlord DB. This is by design: authorization is per-tenant
 * (1.5G.0), and the landlord-side authorization is a deferred
 * separate slice (`1.5G.1-landlord-roles`).
 *
 * The default `Spatie\Permission\Models\Role` resolves its connection
 * to the global default, which in this app is the landlord `pgsql`
 * connection — a DB that has no `roles` table. As a result, every
 * `Role::findOrCreate(...)` and every `$user->roles` relationship
 * join would query the wrong DB and fail with "no existe la
 * relación «roles»".
 *
 * The fix is to bind the model to the `tenant` connection so the
 * queries go to the per-tenant database that actually holds the
 * table. When a request lands on a tenant subdomain, the Spatie
 * multitenancy middleware points the `tenant` connection at the
 * right DB before the User model is loaded, so this binding is
 * consistent with the User's `UsesTenantConnection` setup.
 *
 * Landlord roles (1.5G.1) will need their own subclass bound to
 * the `landlord` connection; that is intentionally out of scope
 * for 1.5G.0.
 */
class Role extends SpatieRole
{
    protected $connection = 'tenant';
}
