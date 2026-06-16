<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\ManualNotificationLog;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ManualNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class NotificationController extends Controller
{
    /**
     * Show the compose form for sending manual notifications.
     */
    public function create(): InertiaResponse
    {
        $tenants = Tenant::query()->orderBy('name')->get();

        return Inertia::render('landlord/notifications/compose', [
            'tenants' => $tenants,
        ]);
    }

    /**
     * Dry-run preview: count recipients per tenant without sending.
     */
    public function preview(Request $request): InertiaResponse
    {
        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'message' => 'required|string|max:5000',
            'tenant_ids' => 'required|array|min:1',
            'tenant_ids.*' => 'exists:tenants,id',
            'send_to_all' => 'boolean',
            'roles' => 'nullable|array',
        ]);

        $tenants = $this->resolveTenants($data);
        $roles = $data['roles'] ?? ['owner', 'tenant-admin'];
        $allTenants = Tenant::query()->orderBy('name')->get();
        $preview = [];

        foreach ($tenants as $tenant) {
            $tenant->makeCurrent();

            try {
                $count = $this->countUsersByRoles($roles);
            } finally {
                DB::purge('tenant');
            }

            $preview[] = [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'recipient_count' => $count,
            ];
        }

        return Inertia::render('landlord/notifications/compose', [
            'tenants' => $allTenants,
            'preview' => $preview,
            'form' => $data,
        ]);
    }

    /**
     * Send notifications and log the event.
     */
    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'message' => 'required|string|max:5000',
            'tenant_ids' => 'required|array|min:1',
            'tenant_ids.*' => 'exists:tenants,id',
            'send_to_all' => 'boolean',
            'roles' => 'nullable|array',
        ]);

        $tenants = $this->resolveTenants($data);
        $roles = $data['roles'] ?? ['owner', 'tenant-admin'];
        $totalRecipients = 0;

        foreach ($tenants as $tenant) {
            $tenant->makeCurrent();

            try {
                $users = $this->getUsersByRoles($roles);
                $totalRecipients += $users->count();

                if ($users->isNotEmpty()) {
                    Notification::send($users, new ManualNotification($data['message'], $data['title'] ?? null));
                }
            } finally {
                DB::purge('tenant');
            }
        }

        ManualNotificationLog::create([
            'title' => $data['title'] ?? null,
            'message' => $data['message'],
            'tenant_ids' => ($data['send_to_all'] ?? false) ? Tenant::pluck('id')->toArray() : $data['tenant_ids'],
            'total_recipients' => $totalRecipients,
            'sent_by' => auth()->id(),
        ]);

        return to_route('landlord.notifications.history')
            ->with('success', "Notification sent to {$totalRecipients} recipient(s).");
    }

    /**
     * Show history of sent manual notifications.
     */
    public function history(): InertiaResponse
    {
        $logs = ManualNotificationLog::with('sender')
            ->orderByDesc('created_at')
            ->paginate(20);

        return Inertia::render('landlord/notifications/history', [
            'logs' => $logs,
        ]);
    }

    /**
     * Resolve tenants based on the request data.
     *
     * If send_to_all is true, returns all tenants. Otherwise, filters
     * by the provided tenant_ids.
     *
     * @return Collection<int, Tenant>
     */
    private function resolveTenants(array $data)
    {
        if (! empty($data['send_to_all'])) {
            return Tenant::query()->get();
        }

        return Tenant::query()->whereIn('id', $data['tenant_ids'])->get();
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

    /**
     * Count users matching the given roles for the current tenant.
     *
     * @param  array<int, string>  $roles
     */
    private function countUsersByRoles(array $roles): int
    {
        try {
            return User::query()
                ->whereHas('roles', fn ($q) => $q->whereIn('name', $roles))
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
