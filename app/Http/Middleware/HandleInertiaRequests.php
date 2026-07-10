<?php

namespace App\Http\Middleware;

use App\Models\Entitlement;
use App\Models\Landlord;
use App\Models\PaymentNotification;
use App\Models\Resource;
use App\Models\SystemConfig;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'auth' => [
                'user' => $this->resolveUserProp($user),
                'is_admin' => $user instanceof Landlord,
                'unread_notifications_count' => $this->resolveUnreadNotificationsCount($user),
                'unread_system_alerts_count' => $this->resolveUnreadSystemAlertsCount($user),
                'unread_payment_notifications_count' => $this->resolveUnreadPaymentNotificationsCount($user),
            ],
            'tenant' => $this->resolveTenantData(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'polling_interval_seconds' => $this->resolvePollingInterval(),
        ];
    }

    private function resolvePollingInterval(): int
    {
        try {
            return (int) SystemConfig::get('admin.polling_interval_seconds', 30);
        } catch (\Throwable) {
            return 30;
        }
    }

    private function resolveTenantData(): ?array
    {
        $current = Tenant::current();

        if (! $current instanceof Tenant) {
            return null;
        }

        return [
            'id' => $current->getKey(),
            'name' => $current->name,
            'domain' => $current->domain,
            'plan_name' => $current->subscription?->plan?->name ?? 'Free',
            'is_free_tier' => $current->isOnFreeTier(),
            'has_free_resources' => Resource::query()
                ->active()
                ->where('is_premium', false)
                ->exists(),
            'has_entitlements' => Entitlement::query()
                ->where('tenant_id', $current->id)
                ->exists(),
            'has_premium_zone' => $current->hasFeature('premium-zone'),
        ];
    }

    private function resolveUserProp(?Authenticatable $user): mixed
    {
        if ($user === null) {
            return null;
        }

        if (! ($user instanceof User || $user instanceof Landlord)) {
            return $user;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $this->resolveAvatar($user),
            'email_verified_at' => $user->email_verified_at?->toJson(),
            'roles' => $this->resolveRoles($user),
        ];
    }

    private function resolveAvatar(Authenticatable $user): ?string
    {
        if (! method_exists($user, 'getFirstMedia')) {
            return null;
        }

        $connection = method_exists($user, 'getConnectionName')
            ? ($user->getConnectionName() ?? config('database.default'))
            : config('database.default');

        try {
            if (! Schema::connection($connection)->hasTable('media')) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        try {
            return $user->avatar;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveRoles(Authenticatable $user): array
    {
        if (! $user instanceof User) {
            return [];
        }

        $connection = method_exists($user, 'getConnectionName')
            ? ($user->getConnectionName() ?? config('database.default'))
            : config('database.default');

        try {
            if (! Schema::connection($connection)->hasTable('roles')) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }

        try {
            return $user->roles?->pluck('name')->toArray() ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function resolveUnreadNotificationsCount(?Authenticatable $user): int
    {
        if (! $user instanceof User && ! $user instanceof Landlord) {
            return 0;
        }

        $connection = method_exists($user, 'getConnectionName')
            ? ($user->getConnectionName() ?? config('database.default'))
            : config('database.default');

        try {
            if (! Schema::connection($connection)->hasTable('notifications')) {
                return 0;
            }
        } catch (\Throwable) {
            return 0;
        }

        try {
            return $user->unreadNotifications()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function resolveUnreadSystemAlertsCount(?Authenticatable $user): int
    {
        if (! $user instanceof Landlord) {
            return 0;
        }

        $connection = method_exists($user, 'getConnectionName')
            ? ($user->getConnectionName() ?? config('database.default'))
            : config('database.default');

        try {
            if (! Schema::connection($connection)->hasTable('notifications')) {
                return 0;
            }
        } catch (\Throwable) {
            return 0;
        }

        try {
            return $user->notifications()
                ->whereNull('read_at')
                ->whereRaw("data::json->>'category' = ?", ['system'])
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function resolveUnreadPaymentNotificationsCount(?Authenticatable $user): int
    {
        if (! $user instanceof Landlord) {
            return 0;
        }

        try {
            $lastViewed = $user->last_viewed_payment_notifications_at;

            $query = PaymentNotification::query();

            if ($lastViewed) {
                $query->where('created_at', '>', $lastViewed);
            }

            return $query->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
