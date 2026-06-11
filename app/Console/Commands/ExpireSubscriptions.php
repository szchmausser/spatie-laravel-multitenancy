<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SubscriptionExpired;
use App\Notifications\SubscriptionExpiringWarning;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ExpireSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire overdue subscriptions and send notifications';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->expireOverdueSubscriptions();
        $this->sendExpiringWarnings();

        return Command::SUCCESS;
    }

    /**
     * Transition Active subscriptions with past ends_at to Expired.
     */
    private function expireOverdueSubscriptions(): void
    {
        $overdue = Subscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->where('ends_at', '<', now())
            ->get();

        $freePlan = Plan::where('slug', 'free')->first();

        foreach ($overdue as $subscription) {
            $oldPlan = $subscription->plan;

            $subscription->update([
                'status' => SubscriptionStatus::Expired,
                'plan_id' => $freePlan?->id,
            ]);

            // Record expiry in history (no actor — CLI command)
            try {
                SubscriptionHistory::record([
                    'subscription_id' => $subscription->id,
                    'tenant_id' => $subscription->tenant_id,
                    'event_type' => SubscriptionEventType::SubscriptionExpired,
                    'old_plan_name' => $oldPlan?->name,
                    'old_plan_price_cents' => $oldPlan?->price_cents,
                    'old_plan_features' => $oldPlan?->features,
                    'old_status' => SubscriptionStatus::Active->value,
                    'new_status' => SubscriptionStatus::Expired->value,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to record subscription history', ['error' => $e->getMessage()]);
            }

            $tenant = $subscription->tenant;
            $tenant->makeCurrent();

            $users = $this->getAdminUsers();
            Notification::send($users, new SubscriptionExpired($subscription));
        }

        if ($overdue->isNotEmpty()) {
            $this->info("Expired {$overdue->count()} subscription(s).");
        }
    }

    /**
     * Send warning notifications for subscriptions expiring within 3 days.
     */
    private function sendExpiringWarnings(): void
    {
        $expiring = Subscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->where('ends_at', '>', now())
            ->where('ends_at', '<=', now()->addDays(3))
            ->get();

        foreach ($expiring as $subscription) {
            $tenant = $subscription->tenant;
            $tenant->makeCurrent();

            if ($this->hasRecentWarning($subscription)) {
                continue;
            }

            $users = $this->getAdminUsers();
            Notification::send($users, new SubscriptionExpiringWarning($subscription));
        }

        if ($expiring->isNotEmpty()) {
            $this->info("Sent warnings for {$expiring->count()} expiring subscription(s).");
        }
    }

    /**
     * Check if a warning notification was already sent within the last 24 hours.
     *
     * Checks the tenant's own notifications table (each tenant has its own DB).
     */
    private function hasRecentWarning(Subscription $subscription): bool
    {
        try {
            return DB::connection('tenant')
                ->table('notifications')
                ->where('type', SubscriptionExpiringWarning::class)
                ->where('notifiable_type', User::class)
                ->where('created_at', '>=', now()->subDay())
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get users with admin roles (owner, tenant-admin) for the current tenant.
     *
     * Assumes a tenant context is already active via makeCurrent().
     * Returns empty collection when permission tables don't exist (e.g. fresh tenant).
     *
     * @return Collection<int, User>
     */
    private function getAdminUsers()
    {
        try {
            return User::query()
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['owner', 'tenant-admin']))
                ->get();
        } catch (\Throwable) {
            return collect();
        }
    }
}
