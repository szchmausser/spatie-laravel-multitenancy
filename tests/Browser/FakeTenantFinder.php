<?php

namespace Tests\Browser;

use Illuminate\Http\Request;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\TenantFinder\TenantFinder;

/**
 * Fake tenant finder for browser tests.
 *
 * Since pest-plugin-browser runs the HTTP server in the same PHP process,
 * binding this finder to the container makes it resolve the tenant for
 * every request — regardless of the HTTP Host header.
 *
 * This avoids DNS/hosts configuration and the DomainTenantFinder's
 * dependency on the request host matching the tenant's domain column.
 */
class FakeTenantFinder extends TenantFinder
{
    public function __construct(
        private readonly IsTenant $tenant,
    ) {}

    public function findForRequest(Request $request): ?IsTenant
    {
        return $this->tenant;
    }
}
