# Tasks: Manual Notification Center

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~600-700 |
| 400-line budget risk | Medium |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 → PR 2 → PR 3 |
| Delivery strategy | ask-on-risk |
| Chain strategy | stacked-to-main |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: Medium

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Backend foundation: migration, model, routes, controller | PR 1 | Base: main. ~350 lines. Tests included. |
| 2 | Frontend: compose, history, admin-panel card | PR 2 | Base: PR 1 branch. ~300 lines. |
| 3 | Browser tests for compose flow + history visibility | PR 3 | Base: PR 2 branch. ~100 lines. |

---

## Phase 1: Foundation (Migration, Model, Routes)

- [ ] 1.1 Create migration `database/migrations/landlord/2026_06_16_000001_create_manual_notification_logs_table.php` — `manual_notification_logs` table with id, title (nullable), message (text), tenant_ids (jsonb), total_recipients (int), sent_by (FK users, cascadeOnDelete), timestamps, index on created_at. Use `Schema::connection('landlord')`.
- [ ] 1.2 Create model `app/Models/ManualNotificationLog.php` — `UsesLandlordConnection`, fillable: title, message, tenant_ids, total_recipients, sent_by. Cast `tenant_ids` as `array`. BelongsTo `Landlord` via `sent_by` (relation named `sender`).
- [ ] 1.3 Add 4 routes to `routes/landlord.php` inside existing `auth + verified + EnsureUserIsAdmin` group: `GET notifications` → `create`, `POST notifications/preview` → `preview`, `POST notifications/send` → `send`, `GET notifications/history` → `history`. Names: `landlord.notifications.create`, `.preview`, `.send`, `.history`.

**Verification**: `php artisan migrate --path=database/migrations/landlord --database=landlord` succeeds. `php artisan route:list --name=landlord.notifications` shows all 4 routes.

---

## Phase 2: Controller

- [ ] 2.1 Create `app/Http/Controllers/Landlord/NotificationController.php` with `create()` action — query all tenants ordered by name, render `landlord/notifications/compose` Inertia page with `tenants` prop.
- [ ] 2.2 Add `preview()` action — validate request (title nullable|string|max:255, message required|string|max:5000, tenant_ids required|array|min:1, tenant_ids.* exists:tenants,id, send_to_all boolean, roles nullable array). Iterate tenants with `makeCurrent()` → `countUsersByRoles()` → `purge('tenant')` in `finally`. Return Inertia render of compose page with `tenants`, `preview`, `form` props.
- [ ] 2.3 Add `send()` action — same validation as preview. Iterate tenants with `makeCurrent()` → `getUsersByRoles()` → `Notification::send()` with `ManualNotification` → accumulate `totalRecipients` → `purge('tenant')` in `finally`. Create `ManualNotificationLog` record. Redirect to history route with success flash.
- [ ] 2.4 Add `history()` action — query `ManualNotificationLog::with('sender')->orderByDesc('created_at')->paginate(20)`. Render `landlord/notifications/history` Inertia page with `logs` prop.
- [ ] 2.5 Add private helpers: `resolveTenants(array $data)` (returns all tenants if `send_to_all`, else `whereIn`), `getUsersByRoles(array $roles)` (try/catch returns collection), `countUsersByRoles(array $roles)` (try/catch returns int).

**Verification**: `php artisan route:list --path=admin/notifications` shows all routes pointing to correct controller methods. Run `php artisan test --compact --filter=NotificationControllerTest`.

---

## Phase 3: Feature Tests

- [ ] 3.1 Create `tests/Feature/Landlord/NotificationControllerTest.php` — test preview returns correct per-tenant counts via `POST notifications/preview` with `tenant_ids`, assert Inertia props contain `preview` array.
- [ ] 3.2 Add test: send dispatches notification + creates log — `POST notifications/send`, assert `ManualNotificationLog` created, use `Notification::fake()` to verify correct users.
- [ ] 3.3 Add test: send without tenants returns 422 validation error.
- [ ] 3.4 Add test: history paginates at 20 — create 25 `ManualNotificationLog` records, `GET notifications/history`, assert 2 pages.
- [ ] 3.5 Add test: connection restored after send — mock `DB::shouldReceive('purge')`, verify called after each tenant iteration.
- [ ] 3.6 Add test: unauthenticated user gets 302 redirect to login.
- [ ] 3.7 Add test: non-admin tenant user gets 403.

**Verification**: `php artisan test --compact --filter=NotificationControllerTest` — all 7 tests pass.

---

## Phase 4: Frontend — Compose Page

- [ ] 4.1 Create `resources/js/pages/landlord/notifications/compose.tsx` — compose form with: title input (optional), message textarea (required, max 5000), tenant checkboxes with "Select all" toggle, role filter multi-select (default: owner, tenant-admin), "Preview" button → POST to `notifications.preview`. Uses `useForm` from `@inertiajs/react`, shadcn `Card`, `Button`, `Checkbox`, `Label`, `Textarea`, `Input`.
- [ ] 4.2 Add preview state to compose.tsx — after dry-run, show same form (read-only) + recipient count table (tenant name, recipient count, total) + "Send" button → POST to `notifications.send` + "Edit" button → back to compose state. Props: `{ tenants, preview?, form? }`.
- [ ] 4.3 Add breadcrumbs `{ title: 'Admin', href: '/admin' }, { title: 'Notifications', href: '#' }` and `layout` export.

**Verification**: `npm run build` succeeds. Visit `/admin/notifications` in browser — form renders with tenant list.

---

## Phase 5: Frontend — History Page + Admin Panel

- [ ] 5.1 Create `resources/js/pages/landlord/notifications/history.tsx` — paginated table (20/page) with columns: Date, Title, Message (truncated), Tenants, Recipients, Sent by. Use same pagination pattern as `subscriptions/history.tsx` (prev/next buttons, page info). Flash success banner on send. Props: `{ logs: Paginated<ManualNotificationLog> }`.
- [ ] 5.2 Modify `resources/js/pages/landlord/admin-panel.tsx` — add "Notifications" card to `cards` array with title: 'Notifications', description: 'Send announcements to tenant users.', href: `create()` from `@/routes/landlord/notifications`, icon: `Bell` from lucide-react, testId: 'admin-card-notifications'.

**Verification**: `npm run build` succeeds. Visit `/admin` — Notifications card visible. Click → compose page. Send notification → history page shows entry.

---

## Phase 6: Browser Tests

- [ ] 6.1 Create `tests/Browser/Landlord/NotificationBrowserTest.php` — test compose flow: visit compose page, fill title + message, select tenants, click preview, verify counts displayed, click send, verify success redirect to history.
- [ ] 6.2 Add test: history shows sent notification — send a notification first, navigate to history, verify entry visible with correct data.

**Verification**: `php artisan test --compact --filter=NotificationBrowserTest` — both tests pass.

---

## Phase 7: Polish & Runway

- [ ] 7.1 Run `vendor/bin/pint --dirty --format agent` to fix code style on all modified PHP files.
- [ ] 7.2 Run `npm run build` to verify frontend compiles cleanly.
- [ ] 7.3 Run full test suite: `php artisan test --compact` — all tests pass including existing ones.

**Verification**: Zero linting errors, zero test failures, no regressions.
