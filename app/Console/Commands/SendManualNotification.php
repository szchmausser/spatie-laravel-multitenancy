<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ManualNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

class SendManualNotification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notification:send
        {message : The notification message to send}
        {--title= : Optional title for the notification}
        {--tenants= : Comma-separated tenant IDs to send to}
        {--all : Send to all active tenants}
        {--roles=owner,tenant-admin : Comma-separated roles to notify (default: owner,tenant-admin)}
        {--dry-run : Preview recipients without sending}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a manual notification to tenant users';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $message = $this->argument('message');
        $title = $this->option('title');
        $dryRun = $this->option('dry-run');
        $sendAll = $this->option('all');
        $tenantIds = $this->option('tenants');
        $roles = array_map('trim', explode(',', $this->option('roles')));

        if (! $sendAll && empty($tenantIds)) {
            $this->error('Specify --tenants=1,2,3 or --all.');

            return Command::FAILURE;
        }

        $tenants = $sendAll
            ? Tenant::query()->get()
            : Tenant::query()->whereIn('id', explode(',', $tenantIds))->get();

        if ($tenants->isEmpty()) {
            $this->error('No matching tenants found.');

            return Command::FAILURE;
        }

        $totalRecipients = 0;

        foreach ($tenants as $tenant) {
            $tenant->makeCurrent();

            $users = $this->getUsersByRoles($roles);

            if ($users->isEmpty()) {
                $this->line("  Tenant {$tenant->id} ({$tenant->name}): no matching users, skipping.");

                continue;
            }

            $totalRecipients += $users->count();

            if ($dryRun) {
                $this->line("  Tenant {$tenant->id} ({$tenant->name}): {$users->count()} recipient(s) — ".$users->pluck('email')->implode(', '));
            } else {
                Notification::send($users, new ManualNotification($message, $title));
                $this->line("  Tenant {$tenant->id} ({$tenant->name}): sent to {$users->count()} user(s).");
            }
        }

        $action = $dryRun ? 'Would send to' : 'Sent to';
        $this->info("{$action} {$totalRecipients} total recipient(s) across {$tenants->count()} tenant(s).");

        return Command::SUCCESS;
    }

    /**
     * Get users matching the given roles for the current tenant.
     *
     * @param  array<int, string>  $roles
     * @return Collection<int, User>
     */
    private function getUsersByRoles(array $roles)
    {
        try {
            return User::query()
                ->whereHas('roles', fn ($q) => $q->whereIn('name', $roles))
                ->get();
        } catch (\Throwable) {
            return collect();
        }
    }
}
