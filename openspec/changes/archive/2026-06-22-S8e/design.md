# Design: S8e — PaymentNotification viewer + reprocess failed

## Technical Approach

Full-stack Inertia page following S8c/S8d pattern: `PaymentNotificationController` → Inertia props → `landlord/payment-notifications/index.tsx`. No API layer. Server-side filter state via query-string params. Reprocess dispatches the existing `IngestPaymentNotification` job for failed notifications.

**Ref**: Spec RF-1 through RF-5, proposal scope.

## Architecture Decisions

### Decision: Route-model binding for reprocess

| Option | Tradeoff |
|--------|----------|
| A: Implicit binding `{notification}` | Works — PaymentNotification has auto-increment PK, no special config needed |
| B: Manual `findOrFail($id)` | More code, same result |

**Choice**: **Option A** — `PaymentNotification $notification` in the controller method. Laravel resolves the model automatically; 404 on missing. Same pattern as S8d's `PaymentMethodConfig`.

### Decision: Validation approach

| Option | Tradeoff |
|--------|----------|
| A: Inline `$request->validate()` in `index()` | Compact, same as AlertController |
| B: Form Request class | More ceremony for a single-use filter validation |

**Choice**: **Option A** — inline validation. Filters have simple rules (`in:pending,success,failed`, `nullable|string|max:20`, `nullable|date`).

### Decision: Job dispatch signature

`IngestPaymentNotification` constructor accepts `PaymentNotification $notification` (SerializesModels). The controller passes the model directly: `IngestPaymentNotification::dispatch($notification)`. No ID extraction needed. The queue serializes and re-hydrates the model on the worker side.

### Decision: Expandable row state

React `useState<number | null>` — single expanded row at a time (accordion-style). Expanding a new row collapses the previous one. Simpler than tracking multiple toggles and avoids tall pages with many expanded rows.

## Data Flow

```
Browser GET /admin/payment-notifications?parse_status=failed&bank_code=BDV
  → PaymentNotificationController@index
  → validate filters → query with where() scopes
  → with('match.payment') → latest() → paginate(20)→withQueryString()
  → Inertia render → { notifications, filters }

Browser clicks "Reprocesar" on a failed row
  → POST /admin/payment-notifications/{notification}/reprocess
  → controller validates parse_status === 'failed'
  → IngestPaymentNotification::dispatch($notification)
  → redirect()->back() with flash success
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Models/PaymentNotification.php` | Modify | Add `match(): HasOne` relationship (RF-5) |
| `app/Http/Controllers/Landlord/PaymentNotificationController.php` | Create | `index()` with filters + pagination, `reprocess()` with guard + dispatch |
| `routes/landlord.php` | Modify | Add GET + POST routes inside admin middleware group |
| `resources/js/pages/landlord/payment-notifications/index.tsx` | Create | Filterable table, expandable row, reprocess button, pagination |
| `resources/js/pages/landlord/admin-panel.tsx` | Modify | Add "Notificaciones" card entry (RF-4) |
| `tests/Feature/Landlord/PaymentNotificationControllerTest.php` | Create | Feature tests covering all filter + reprocess + auth scenarios |

## Interfaces / Contracts

### PaymentNotification — match() relationship

```php
// In app/Models/PaymentNotification.php
public function match(): HasOne
{
    return $this->hasOne(PaymentMatch::class, 'payment_notification_id');
}
```

### Inertia page props

```typescript
interface PaymentNotificationItem {
    id: number;
    bank_code: string;
    raw_text: string;
    parse_status: 'pending' | 'parsed' | 'failed';
    parsed_data: Record<string, unknown> | null;
    parse_error: string | null;
    parsed_at: string | null;
    created_at: string;
    match: {
        id: number;
        parsed_reference: string;
        parsed_amount_cents: number;
        match_status: string;
        payment: { id: number; status: string; amount_cents: number } | null;
    } | null;
}

interface PaymentNotificationPageProps {
    notifications: {
        data: PaymentNotificationItem[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: {
        parse_status: string | null;
        bank_code: string | null;
        from: string | null;
        to: string | null;
    };
}
```

### Route definitions

```php
Route::get('/payment-notifications', [PaymentNotificationController::class, 'index'])->name('payment-notifications.index');
Route::post('/payment-notifications/{notification}/reprocess', [PaymentNotificationController::class, 'reprocess'])->name('payment-notifications.reprocess');
```

Both inside existing `prefix('admin')->middleware(['auth', 'verified', EnsureUserIsAdmin::class])->name('landlord.')->group(...)`.

## Testing Strategy

| Layer | What | Approach |
|-------|------|----------|
| Feature | Index default | 200, 20 records, pagination links present |
| Feature | Filter parse_status | `?parse_status=failed` → only failed items |
| Feature | Filter bank_code | `?bank_code=BDV` → only matching bank |
| Feature | Filter date range | `?from=...&to=...` → records within range |
| Feature | Reprocess success | POST → job dispatched (assert via `Bus::fake()`), flash success |
| Feature | Reprocess non-failed | POST on parsed notification → 422, no job dispatched |
| Feature | Reprocess 404 | POST non-existent → 404 |
| Feature | Unauthorized | Non-admin user → 403 |
| Feature | Empty state | No matching records → 200, data: [] |

## Migration / Rollout

No migration required. The `match()` relationship reads existing FK (`payment_notification_id` on `payment_matches`). The page becomes accessible immediately after deploy.

**Rollback**: Remove routes from `landlord.php`. Delete controller + page + test files. Revert `match()` addition from `PaymentNotification.php`. Remove card from `admin-panel.tsx`. No schema changes to revert.

## Open Questions

- None resolved — all decisions documented above.
