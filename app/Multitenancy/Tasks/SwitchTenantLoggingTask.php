<?php

namespace App\Multitenancy\Tasks;

use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

class SwitchTenantLoggingTask implements SwitchTenantTask
{
    public function makeCurrent(IsTenant $tenant): void
    {
        Log::shareContext(['tenant_id' => $tenant->getKey()]);
    }

    public function forgetCurrent(): void
    {
        Log::withoutContext();
        Log::flushSharedContext();
    }
}
