<?php

namespace App\Multitenancy\Tasks;

use Illuminate\Support\Facades\Storage;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

class SwitchFilesystemTask implements SwitchTenantTask
{
    protected ?string $originalPrefix = null;

    protected ?string $originalMediaLibraryDisk = null;

    public function __construct()
    {
        $this->originalPrefix ??= config('filesystems.disks.tenant.prefix');
        $this->originalMediaLibraryDisk ??= config('media-library.disk_name');
    }

    public function makeCurrent(IsTenant $tenant): void
    {
        config()->set('filesystems.disks.tenant.prefix', "tenant_{$tenant->getKey()}");
        config()->set('media-library.disk_name', 'tenant');
        app()->forgetInstance('filesystem');
        Storage::clearResolvedInstance('filesystem');
    }

    public function forgetCurrent(): void
    {
        config()->set('filesystems.disks.tenant.prefix', $this->originalPrefix);
        config()->set('media-library.disk_name', $this->originalMediaLibraryDisk);
        app()->forgetInstance('filesystem');
        Storage::clearResolvedInstance('filesystem');
    }
}
