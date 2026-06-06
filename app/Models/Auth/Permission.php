<?php

namespace App\Models\Auth;

use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Spatie Permission `Permission` model bound to the `tenant`
 * connection.
 *
 * See {@see \App\Models\Auth\Role} for the full rationale. The
 * same argument applies: the permission table is gated to the
 * `tenant` connection, so the model that queries it must be
 * bound to that connection too. The default Spatie model would
 * query the landlord DB (which has no `permissions` table) and
 * blow up on the first `$user->can(...)` check.
 *
 * Landlord permissions (1.5G.1) will need their own subclass
 * bound to the `landlord` connection; that is intentionally out
 * of scope for 1.5G.0.
 */
class Permission extends SpatiePermission
{
    protected $connection = 'tenant';
}
