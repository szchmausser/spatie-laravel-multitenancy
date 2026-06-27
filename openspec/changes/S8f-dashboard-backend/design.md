# Design: Dashboard de Conciliación — Backend

## Technical Approach

Nuevo controller `ReconciliationDashboardController` con 2 endpoints siguiendo el patrón `AlertController` (métodos privados por KPI, sin service layer). Render vía Inertia a una nueva página React. La card de acceso se agrega al array existente en `admin-panel.tsx`.

## Architecture Decisions

| Opción | Tradeoffs | Decisión |
|--------|-----------|----------|
| **Timeline: PHP collections vs SQL UNION** | UNION requiere raw SQL + mapeo manual de tipos dispares; collections permite reusar Eloquent scopes y formatear cada fuente con su lógica. Performance irrelevante (< 60 rows total). | **PHP collections** — 3 queries separadas, map a estructura común, `sortByDesc()->take(20)` |
| **Controller methods vs Service layer** | Todo el codebase usa métodos privados inline (AlertController, PaymentNotificationController). Service layer añadiría abstracción sin beneficiar reutilización (solo este controller usa estos KPIs). | **Métodos privados** en el controller — consistencia con codebase |
| **Orphan threshold: SystemConfig vs constante** | Constante requiere deploy para ajustar; `SystemConfig::get()` permite cambio via UI (S8b) sin deploy, consistente con `shadow_mode_enabled` y la migración planificada a `system_configs`. | **`SystemConfig::get('reconciliation.orphan_threshold_minutes', 30)`** — default 30, configurable sin deploy |
| **Active alerts: notifications table query** | Dos approaches: (a) query directa a `notifications` via `DB::table()` para performance, (b) `Auth::user()->notifications()` para consistencia con AlertController. | **`Auth::user()->notifications()`** — consistencia, el query tiene index por `notifiable_id` + `notifiable_type` |

## Data Flow

```
Request → GET /admin/reconciliation
         └── ReconciliationDashboardController@index
              ├── matchRate()          → PaymentMatch groupBy match_status
              ├── autoverifiedToday()  → Payment where verified_at=today, verified_by=null
              ├── activeAlerts()       → Auth::user()->notifications() where type=SystemAlert, read_at=null
              ├── failedNotifications()→ PaymentNotification::failed()->count()
              ├── shadowModeStatus()   → SystemConfig::get()
              ├── orphanedPayments()   → Payment::whereDoesntHave('paymentMatch')
              ├── orphanedNotifications()→ PaymentMatch unmatched
              └── timeline()           → 3 queries → map → merge → sort → take(20)
              │
              └── inertia('landlord/reconciliation', [...KPIs])
```

## Controller Structure

```php
class ReconciliationDashboardController extends Controller
{
    use UsesLandlordConnection;

    public function index(): InertiaResponse;       // arma array con 8 KPIs
    public function toggleShadowMode(Request): RedirectResponse;  // PATCH handler

    private function matchRate(): array;             // {percentage, total, matched, by_status}
    private function autoverifiedToday(): int;
    private function activeAlerts(): int;
    private function failedNotifications(): int;
    private function shadowModeStatus(): bool;
    private function orphanedPayments(): Collection;  // {id, amount_cents, created_at, reference}
    private function orphanedNotifications(): Collection; // {id, amount_cents, created_at}
    private function timeline(): array;              // [{type, description, timestamp, url}]
}
```

## Cada KPI

| KPI | Query | Edge case |
|-----|-------|-----------|
| **Match rate** | `PaymentMatch::selectRaw("match_status, COUNT(*) as count")->groupBy('match_status')->get()->pluck('count', 'match_status')` | total=0 → percentage=0 |
| **Autoverificados** | `Payment::whereDate('verified_at', today())->whereNull('verified_by')->count()` | Sin registros → 0 |
| **Alertas activas** | `Auth::user()->notifications()->where('type', SystemAlert::class)->whereNull('read_at')->count()` | Sin notificaciones → 0 |
| **Notif. fallidas** | `PaymentNotification::failed()->count()` | scope existe |
| **Shadow mode** | `SystemConfig::get('reconciliation.shadow_mode_enabled', false)` | Sin registro en DB → false |
| **Payments huérfanos** | `Payment::where('status', PaymentStatus::Pending)->where('created_at', '<', now()->subMinutes(SystemConfig::get('reconciliation.orphan_threshold_minutes', 30)))->whereDoesntHave('paymentMatch')->get(['id', 'amount_cents', 'created_at', 'reference'])` | Collection vacía |
| **Notif. huérfanas** | `PaymentMatch::where('match_status', 'unmatched')->where('created_at', '<', now()->subMinutes(SystemConfig::get('reconciliation.orphan_threshold_minutes', 30)))->get(['id', 'parsed_amount_cents', 'created_at'])` → rename `parsed_amount_cents` a `amount_cents` | Collection vacía |
| **Timeline** | 3 queries: (1) PaymentMatch matched recientes, (2) PaymentNotification recientes, (3) Payment verificados recientes → map a `{type, description, timestamp, url}` → `collect()->sortByDesc('timestamp')->take(20)->values()` | Empty → array vacío |

## Shadow Toggle

- `PATCH /admin/reconciliation/shadow-mode` → `toggleShadowMode(Request)`
- Validación: `$request->validate(['enabled' => ['required', 'boolean']])`
- `SystemConfig::set('reconciliation.shadow_mode_enabled', $request->enabled)` — invalida cache automáticamente
- `redirect()->back()->with('success', ...)`

## Admin Card

En `resources/js/pages/landlord/admin-panel.tsx`, agregar al array `cards`:

```ts
{
    title: 'Dashboard de Conciliación',
    description: 'KPIs de conciliación, pagos huérfanos y timeline.',
    href: '/admin/reconciliation',
    icon: LayoutDashboard,
    testId: 'admin-card-reconciliation',
}
```

Import `LayoutDashboard` desde `lucide-react`. Insertar después de `Notificaciones Bancarias`.

## Routes

En `routes/landlord.php`, dentro del group admin:

```php
use App\Http\Controllers\Landlord\ReconciliationDashboardController;

// Reconciliation dashboard
Route::get('reconciliation', [ReconciliationDashboardController::class, 'index'])->name('reconciliation.index');
Route::patch('reconciliation/shadow-mode', [ReconciliationDashboardController::class, 'toggleShadowMode'])->name('reconciliation.shadow-mode');
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Http/Controllers/Landlord/ReconciliationDashboardController.php` | Create | Controller con index + toggleShadowMode + 8 métodos privados |
| `resources/js/pages/landlord/reconciliation/index.tsx` | Create | Inertia page — recibirá los KPIs como props |
| `routes/landlord.php` | Modify | +2 rutas: GET + PATCH |
| `resources/js/pages/landlord/admin-panel.tsx` | Modify | +1 card, import LayoutDashboard |
| `tests/Feature/Landlord/ReconciliationDashboardControllerTest.php` | Create | Feature tests |

## Testing Strategy

| Layer | What | Approach |
|-------|------|----------|
| Unit | Match rate calculation | Controller test: crear PaymentMatch con distintos status, assert percentage |
| Unit | Orphan queries | Factory con created_at old vs recent, assert colección filtrada |
| Unit | Timeline merge | Crear registros en 3 tablas, assert merged array size + sort |
| Feature | GET /admin/reconciliation | Admin autenticado, assert Inertia component + KPIs en props |
| Feature | PATCH shadow-mode enabled=true/false | Assert redirect + SystemConfig::get() refleja cambio |
| Feature | PATCH shadow-mode invalid body | Assert validation error (missing enabled, non-boolean) |
| Feature | 401 sin auth | Guest → redirect to login |
| Feature | 403 sin admin | Non-admin landlord → 403 |

## Open Questions

- [ ] ¿Timeline incluye URL a detalles? Se usará `route('landlord.payment-notifications.index')` genérica para matches/notifications y `null` para verificaciones sin order pública. Confirmar en tareas.
