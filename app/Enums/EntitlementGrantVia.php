<?php

namespace App\Enums;

/**
 * How an entitlement was granted to a tenant+user+resource triple.
 *
 * Plan     - granted automatically because the resource is included
 *            in the tenant's plan via the plan_resource pivot table.
 *            Entitlement rows are bookkeeping so the UI can answer
 *            "do I have access?" without re-deriving it from the plan
 *            every time.
 *
 * Purchase - granted through the (Phase 1.5) auto-approve flow,
 *            which Phase 2 will replace with a real payment + webhook
 *            confirmation. The same value is kept so the entitlement
 *            row records intent even when the payment step is later
 *            inserted before the INSERT.
 *
 * Admin    - granted manually by a landlord operator (out of scope
 *            for the current phase but reserved in the enum so the
 *            column is forward-compatible).
 */
enum EntitlementGrantVia: string
{
    case Plan = 'plan';
    case Purchase = 'purchase';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Plan => 'Plan',
            self::Purchase => 'Purchase',
            self::Admin => 'Admin',
        };
    }
}
