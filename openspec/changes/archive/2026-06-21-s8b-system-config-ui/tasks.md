# Tasks: S8b SystemConfig UI

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 250–350 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | single-pr |
| Chain strategy | pending |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Low

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Full S8b — controller, routes, page, admin card, tests | PR 1 | Self-contained, ~300 lines |

## Phase 1: Backend Foundation

- [x] 1.1 Create `app/Http/Controllers/Landlord/SystemConfigController.php` — `index()` returns `Inertia::render('landlord/system-configs/index', ['grouped' => SystemConfig::all()->groupBy('group')])`. `update(SystemConfig $systemConfig)` validates `{ value: 'required|string' }`, coerces type based on `$systemConfig->type` (boolean → `filter_var(FILTER_VALIDATE_BOOLEAN)`, integer → `(int)`), calls `SystemConfig::set($systemConfig->key, $coerced, $systemConfig->type)`, redirects back with flash success. Catches `InvalidArgumentException` → throws `ValidationException` with `{ value: [message] }` for 422.
- [x] 1.2 Add routes in `routes/landlord.php` inside the admin group: `Route::get('system-configs', [SystemConfigController::class, 'index'])->name('admin.system-configs');` and `Route::put('system-configs/{system_config}', [SystemConfigController::class, 'update'])->name('admin.system-configs.update');`. Add `use` import for `SystemConfigController`.

## Phase 2: Frontend — SystemConfig Page

- [x] 2.1 Create `resources/js/pages/landlord/system-configs/index.tsx`. Import Dialog components from `@/components/ui/dialog`, Button, Input, Label, Checkbox (or Switch if available), Head from Inertia. Props: `{ grouped: Record<string, SystemConfig[]> }`.
- [x] 2.2 Render grouped configs: iterate `Object.entries(grouped)`, each group renders a Card with `CardHeader` (group name) and table rows. Each row: key (Badge/monospace), value preview, type badge, description, Edit button.
- [x] 2.3 Implement edit modal with type-aware input: `boolean` → Checkbox with `checked`/`onCheckedChange`, `integer` → `<Input type="number">`, `string` with key starting with `regex_` → `<textarea>`, other `string` → `<Input type="text">`. Modal state: `{ open: boolean, config: SystemConfig | null }`. Submit via `router.put(route('landlord.admin.system-configs.update', config.id), { value }, { onSuccess: () => setOpen(false), preserveScroll: true })`. Show validation errors from `usePage().props.errors`.
- [x] 2.4 Add `Head title` and breadcrumbs: `[{ title: 'Admin', href: '/admin' }, { title: 'Configuración del Sistema', href: '/admin/system-configs' }]`. Export `layout` with breadcrumbs.

## Phase 3: Admin Panel Entry

- [x] 3.1 Add card to `resources/js/pages/landlord/admin-panel.tsx` cards array: `{ title: 'Configuración del Sistema', description: 'Gestionar configuraciones dinámicas del sistema', icon: Settings, href: '/admin/system-configs', testId: 'admin-card-system-configs' }`. Add import: `Settings` from `lucide-react`.

## Phase 4: Testing

- [x] 4.1 Feature test `tests/Feature/Landlord/SystemConfigControllerTest.php`: test admin can GET index and see grouped configs (assert Inertia component + props); test non-admin gets 403; test PUT update persists new value (assert DB has value); test PUT with invalid regex returns 422 with error message; test PUT with non-numeric in integer field returns 422. Use existing `SystemConfig` model and `EnsureUserIsAdmin` middleware.
- [x] 4.2 Feature test for admin panel card: test GET `/admin` page includes a link to `/admin/system-configs`.

## Phase 5: Polish

- [x] 5.1 Run `vendor/bin/pint --dirty --format agent` to format PHP files.
- [x] 5.2 Run `php artisan test --compact --filter=SystemConfigController` to verify all tests pass.
