# Design: Manual Notification Center

## Context

The landlord admin needs a UI to compose and send cross-tenant notifications. The existing `SendManualNotification` artisan command already handles the core logic — iterate tenants, `makeCurrent()` per tenant, query users by roles, send via `Notification::send()` — but it has no web interface, no dry-run preview, and no audit trail. This slice adds a dedicated admin page at `/admin/notifications` with compose, preview, send, and history views, plus a `manual_notification_logs` table for audit.

The existing `ManualNotification` class is unchanged. It sends via `['database', 'mail']` channels and stores `title` + `message` in the notification data payload. The new log table captures what the landlord sent, not what was delivered.

## Architecture Overview

```
Landlord admin panel (/admin)
  └─ NotificationController (landlord DB)
       ├─ GET  /admin/notifications          → compose page (tenant list, form)
       ├─ POST /admin/notifications/preview  → dry-run (per-tenant recipient counts)
       ├─ POST /admin/notifications/send     → dispatch + log
       └─ GET  /admin/notifications/history  → paginated log table

Per-tenant operation (preview + send):
  foreach ($tenants as $tenant) {
      $tenant->makeCurrent();
      // query User::whereHas('roles') in tenant DB
      // send Notification::send() or count recipients
      app('db')->purge('tenant');  // ALWAYS restore landlord connection
  }
```

## Database Schema

New migration: `database/migrations/landlord/2026_06_16_000001_create_manual_notification_logs_table.php`

```php
Schema::connection('landlord')->create('manual_notification_logs', function (Blueprint $table) {
    $table->id();
    $table->string('title')->nullable();
    $table->text('message');
    $table->jsonb('tenant_ids');          // [1, 3, 7] — tenants targeted
    $table->integer('total_recipients');  // sum across all tenants
    $table->foreignId('sent_by')->constrained('users')->cascadeOnDelete();
    $table->timestamps();

    $table->index('created_at');
});
```

**Rationale**: `tenant_ids` as jsonb avoids a pivot table for a write-once audit log. `sent_by` links to the landlord `users` table (the admin who sent it). No `status` column — if the request completes, the log is written; partial failures are logged per-tenant in the `tenant_ids` array with a future `results` jsonb column if needed.

## Routes

Added to `routes/landlord.php` inside the existing `auth + verified + EnsureUserIsAdmin` group:

```php
// Manual notifications
Route::get('notifications', [NotificationController::class, 'create'])->name('notifications.create');
Route::post('notifications/preview', [NotificationController::class, 'preview'])->name('notifications.preview');
Route::post('notifications/send', [NotificationController::class, 'send'])->name('notifications.send');
Route::get('notifications/history', [NotificationController::class, 'history'])->name('notifications.history');
```

**Design choice**: `create` instead of `index` because the primary action is composing a new notification. History is a separate route, not a tab. This matches the `SubscriptionHistoryController` pattern where history is a dedicated page.

## Controller: `Landlord\NotificationController`

```php
class NotificationController extends Controller
{
    // GET /admin/notifications — render compose form
    public function create(): InertiaResponse
    {
        $tenants = Tenant::query()->orderBy('name')->get();
        return Inertia::render('landlord/notifications/compose', [
            'tenants' => $tenants,
        ]);
    }

    // POST /admin/notifications/preview — dry-run with recipient counts
    public function preview(Request $request): InertiaResponse
    {
        $validated = $request->validate([
            'title'       => 'nullable|string|max:255',
            'message'     => 'required|string|max:5000',
            'tenant_ids'  => 'required|array|min:1',
            'tenant_ids.*'=> 'exists:tenants,id',
            'send_to_all' => 'boolean',
            'roles'       => 'nullable|array',
            'roles.*'     => 'string',
        ]);

        $tenants = $this->resolveTenants($validated);
        $preview = [];

        foreach ($tenants as $tenant) {
            $tenant->makeCurrent();
            $count = $this->countUsersByRoles($validated['roles'] ?? ['owner', 'tenant-admin']);
            $preview[] = [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'recipient_count' => $count,
            ];
            app('db')->purge('tenant');
        }

        return Inertia::render('landlord/notifications/compose', [
            'tenants' => Tenant::query()->orderBy('name')->get(),
            'preview' => $preview,
            'form' => $validated,
        ]);
    }

    // POST /admin/notifications/send — dispatch + log
    public function send(Request $request): RedirectResponse
    {
        $validated = $request->validate([...]); // same as preview

        $tenants = $this->resolveTenants($validated);
        $totalRecipients = 0;

        foreach ($tenants as $tenant) {
            $tenant->makeCurrent();
            $users = $this->getUsersByRoles($validated['roles'] ?? ['owner', 'tenant-admin']);
            if ($users->isNotEmpty()) {
                Notification::send($users, new ManualNotification(
                    $validated['message'],
                    $validated['title'] ?? null,
                ));
                $totalRecipients += $users->count();
            }
            app('db')->purge('tenant');
        }

        ManualNotificationLog::create([
            'title' => $validated['title'] ?? null,
            'message' => $validated['message'],
            'tenant_ids' => collect($tenants)->pluck('id')->toArray(),
            'total_recipients' => $totalRecipients,
            'sent_by' => $request->user()->id,
        ]);

        return redirect()->route('landlord.notifications.history')
            ->with('success', "Notification sent to {$totalRecipients} recipient(s).");
    }

    // GET /admin/notifications/history — paginated log
    public function history(): InertiaResponse
    {
        $logs = ManualNotificationLog::with('sender')
            ->orderByDesc('created_at')
            ->paginate(20);

        return Inertia::render('landlord/notifications/history', [
            'logs' => $logs,
        ]);
    }

    // --- Private helpers (extracted from SendManualNotification command) ---

    private function resolveTenants(array $data): Collection
    {
        if (!empty($data['send_to_all'])) {
            return Tenant::query()->get();
        }
        return Tenant::query()->whereIn('id', $data['tenant_ids'])->get();
    }

    private function getUsersByRoles(array $roles): Collection
    {
        try {
            return User::query()
                ->whereHas('roles', fn ($q) => $q->whereIn('name', $roles))
                ->get();
        } catch (\Throwable) {
            return collect();
        }
    }

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
```

## Connection Handling Pattern

Every tenant iteration follows this exact pattern — no exceptions:

```php
foreach ($tenants as $tenant) {
    $tenant->makeCurrent();
    // ... query User (tenant DB) / send notification ...
    app('db')->purge('tenant');  // CRITICAL: restore landlord connection
}
```

**Why `purge` and not ` disconnect`**: `purge()` forces Laravel to create a fresh connection on next use with the current config. After `purge('tenant')`, the next query on the `tenant` connection will re-read `config('database.connections.tenant.database')` — which Spatie resets to `null` for the landlord context. This is the same pattern used in `Tenant::forgetConnection()` and `SwitchTenantLoggingTask`.

**Risk**: If a exception occurs between `makeCurrent()` and `purge()`, the tenant connection leaks. Mitigation: wrap the loop body in `try/finally` with `purge` in the `finally` block.

```php
foreach ($tenants as $tenant) {
    $tenant->makeCurrent();
    try {
        // ... tenant operations ...
    } finally {
        app('db')->purge('tenant');
    }
}
```

## Frontend Component Structure

### `resources/js/pages/landlord/notifications/compose.tsx`

Single page with two states: **compose** (default) and **preview** (after dry-run).

**Compose state**:
- Title input (optional, text)
- Message textarea (required, max 5000)
- Tenant selection: checkboxes with "Select all" toggle
- Role filter: multi-select dropdown (default: `owner`, `tenant-admin`)
- "Preview" button → POST to `notifications/preview`

**Preview state**:
- Same form (read-only)
- Recipient count table: tenant name | recipient count | total
- "Send" button → POST to `notifications/send`
- "Edit" button → back to compose state

**Props**: `{ tenants, preview?, form? }` — `preview` and `form` are present only after a dry-run.

Uses `useForm` from `@inertiajs/react`, shadcn `Card`, `Button`, `Badge`, `Checkbox`, `Label`, `Textarea`, `Input`. Follows existing patterns from `tenant/show.tsx` and `subscriptions/history.tsx`.

### `resources/js/pages/landlord/notifications/history.tsx`

Paginated table (20/page) matching the `subscriptions/history.tsx` pattern:

- Columns: Date | Title | Message (truncated) | Tenants | Recipients | Sent by
- Pagination: prev/next buttons with page info
- Flash success banner on send

**Props**: `{ logs: Paginated<ManualNotificationLog> }`

### `app/Models/ManualNotificationLog.php`

```php
class ManualNotificationLog extends Model
{
    use UsesLandlordConnection;

    protected $fillable = ['title', 'message', 'tenant_ids', 'total_recipients', 'sent_by'];
    protected $casts = ['tenant_ids' => 'array'];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Landlord::class, 'sent_by');
    }
}
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/landlord/2026_06_16_000001_create_manual_notification_logs_table.php` | Create | Landlord DB migration for audit log |
| `app/Models/ManualNotificationLog.php` | Create | Eloquent model with `UsesLandlordConnection` |
| `app/Http/Controllers/Landlord/NotificationController.php` | Create | Compose, preview, send, history |
| `routes/landlord.php` | Modify | Add 4 notification routes |
| `resources/js/pages/landlord/notifications/compose.tsx` | Create | Compose + preview page |
| `resources/js/pages/landlord/notifications/history.tsx` | Create | Paginated history page |
| `resources/js/pages/landlord/admin-panel.tsx` | Modify | Add "Notifications" card to dashboard grid |

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Feature | Preview returns correct per-tenant counts | `POST notifications/preview` with tenant_ids, assert Inertia props contain `preview` array with counts |
| Feature | Send dispatches notification + creates log | `POST notifications/send`, assert `ManualNotificationLog` created, assert `Notification::fake()` received correct users |
| Feature | Send without tenants returns 422 | `POST notifications/send` with empty `tenant_ids`, assert validation error |
| Feature | History paginates at 20 | Create 25 logs, `GET notifications/history`, assert 2 pages |
| Feature | Connection restored after send | Mock `DB::shouldReceive('purge')`, verify called after each tenant iteration |
| Feature | Unauthenticated user redirected | `GET notifications/create` without auth, assert 302 to login |
| Feature | Non-admin gets 403 | `GET notifications/create` as tenant user, assert 403 |
| Browser | Compose flow: fill form → preview → send | Navigate, fill inputs, click preview, verify counts, click send, verify success |
| Browser | History shows sent notification | Send a notification, navigate to history, verify entry visible |

## Migration / Rollout

1. Run migration: `php artisan migrate --path=database/migrations/landlord --database=landlord`
2. Regenerate Wayfinder routes: `php artisan wayfinder:generate`
3. No feature flag needed — the page is admin-only and the feature is additive.
4. No data migration — new table only.

## Open Questions

- [ ] Should the "Send to all" toggle be a checkbox or a separate radio? (Recommendation: checkbox, consistent with existing toggle patterns)
- [ ] Should we add a `results` jsonb column to `manual_notification_logs` now for per-tenant success/failure tracking, or defer to a future iteration? (Recommendation: defer — the current scope sends synchronously and either all succeed or the request fails)
