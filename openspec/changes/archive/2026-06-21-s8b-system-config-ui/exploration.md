## Exploration: S8b SystemConfig UI

### Current State

**SystemConfig Model** (`app/Models/SystemConfig.php`):
- Uses `UsesLandlordConnection` — all data lives in the landlord DB
- Fields: `group`, `key` (unique), `value` (text), `type` (string/integer/boolean/json), `description`
- Sentinel-based cache pattern (1h TTL) in `get()` method
- `set()` auto-detects type from PHP value and derives `group` from key prefix
- `save()` override invalidates cache
- Boot-level validation for `regex_*` keys: compiles regex, checks required named groups `(?<amount>)`, `(?<reference>)`
- Currently 10 seeded configs across 4 groups: payment (5), reconciliation (4 incl. 2 regex), device (1)

**Config Groups & Keys**:
| Group | Key | Type | Current Value |
|-------|-----|------|---------------|
| payment | default_gateway | string | pago_movil |
| payment | order_expiry_hours | integer | 48 |
| payment | pago_movil_phone | string | 04141234567 |
| payment | pago_movil_bank | string | 0102 |
| payment | pago_movil_rif | string | J-12345678-9 |
| reconciliation | match_window_hours | integer | 72 |
| reconciliation | shadow_mode_enabled | boolean | true |
| reconciliation | regex_bdv | string | /Recibiste\s+un.../i |
| reconciliation | regex_bnc | string | /BNC\s+Pago.../i |
| device | heartbeat_interval_minutes | integer | 5 |

**Consumers of SystemConfig**:
- `ReconciliationOrchestrator` — reads `match_window_hours`, `shadow_mode_enabled`
- `PaymentService` — reads `order_expiry_hours`
- `PaymentNotificationParser` — reads `regex_{bankCode}`
- `IngestPaymentNotification` job — reads `shadow_mode_enabled`
- `ExpirePendingPayments` command — reads `match_window_hours`
- `Tenant\PaymentController` — reads `pago_movil_*` fallback values

**No existing controller or UI for SystemConfig** — all management is done via tinker/seeder.

### Affected Areas

- `app/Http/Controllers/Landlord/SystemConfigController.php` — **NEW**: index + update endpoints
- `routes/landlord.php` — add GET + PUT routes for system-configs
- `resources/js/pages/landlord/system-configs/index.tsx` — **NEW**: grouped table with inline/modal editing
- `resources/js/pages/landlord/admin-panel.tsx` — add SystemConfigs card entry

**Reference patterns (existing code to follow)**:
- `app/Http/Controllers/Landlord/OrderController.php` — Inertia::render pattern, Landlord namespace
- `resources/js/pages/admin/orders/index.tsx` — table layout with search, Card/CardHeader/CardContent pattern
- `resources/js/pages/landlord/admin-panel.tsx` — cards array pattern with icon, title, description, href
- `app/Http/Middleware/EnsureUserIsAdmin.php` — already applied to all `/admin/*` routes
- `routes/landlord.php` — route group with auth, verified, EnsureUserIsAdmin middleware

**UI Components available** (no Switch component — use Checkbox or custom toggle):
- Card, CardHeader, CardTitle, CardDescription, CardContent
- Button, Input, Badge, Dialog
- Label, Select, Checkbox
- No Switch component exists — boolean toggles need Checkbox or custom implementation

### Approaches

1. **Inline editing in a grouped table** — Table rows per config, value field becomes editable on click/focus
   - Pros: Simple, no extra routes, consistent with existing list pages
   - Cons: Complex state management for multiple edits, harder validation feedback
   - Effort: Medium

2. **Modal per config on edit** — Click row → open Dialog with form fields tailored to type
   - Pros: Clear separation, easy validation per type, better UX for regex editing (multiline)
   - Cons: Extra click, slightly heavier component
   - Effort: Medium

3. **Dedicated edit page per config** — Separate route `GET /admin/system-configs/{id}/edit`
   - Pros: Full form experience, complex validation for regex
   - Cons: Overkill for simple value changes, breaks the "overview" feel
   - Effort: Low (but wrong UX fit)

### Recommendation

**Approach 2: Modal per config on edit** — Best balance of UX and complexity.

- Group configs by `group` field, render each group as a Card section
- Each row shows: key (read-only badge), value (editable input), type badge, description
- Boolean configs (`shadow_mode_enabled`) get a checkbox/toggle instead of text input
- Regex configs (`regex_*`) get a textarea in the modal with a "Test" sample area
- Integer configs get number input
- String configs get text input
- Save via `router.put()` (Inertia) with optimistic update
- Validation errors from the model boot (regex validation) surface in the modal

**Controller**: Simple `index()` returning `Inertia::render('landlord/system-configs', ['configs' => $configs])` and `update()` using `SystemConfig::set()` which handles cache invalidation.

**Route registration**: Two routes in the existing `landlord.php` route group:
```php
Route::get('system-configs', [SystemConfigController::class, 'index'])->name('system-configs.index');
Route::put('system-configs/{systemConfig}', [SystemConfigController::class, 'update'])->name('system-configs.update');
```

### Risks

- **Regex validation on save**: The model's `saving` boot throws `InvalidArgumentException` on invalid regex. The controller must catch this and return a 422 validation error, not a 500.
- **Cache inconsistency**: Must use `SystemConfig::set()` or `->save()`, never `SystemConfig::where()->update()` — the latter bypasses cache invalidation.
- **No Switch UI component**: Boolean toggle needs a Checkbox or custom styled toggle. Checkbox is simpler and already available.
- **Type coercion on save**: The frontend sends string values; the controller must cast to the correct type before calling `SystemConfig::set()`.

### Ready for Proposal
Yes
