# Design: S8b SystemConfig UI

## Technical Approach

Controller with two endpoints (index + update) following the existing `OrderController` pattern — `Inertia::render` for index, redirect back with flash for update. Inertia page with table grouped by `group` field, modal (Dialog) editing per config with type-aware inputs. Cache invalidation delegated entirely to `SystemConfig::set()`. Regex validation error from model boot caught in controller → `ValidationException` for 422 + Inertia error prop.

## Architecture Decisions

| Option | Alternatives | Rationale |
|--------|-------------|-----------|
| Modal editing per config | Inline editing (edit-in-place rows) | Modal gives space for type-specific inputs + validation feedback; avoids layout shifting; less per-row state. Same Radix Dialog used by rest of app. |
| Controller pattern = OrderController | Resource controller, Action classes | Consistent with existing landlord controllers. Index returns Inertia render; update delegates to model. No extra abstraction needed — 2 endpoints only. |
| Cache: delegate to `SystemConfig::set()` | Manual `Cache::forget()` in controller | Model already handles cache invalidation internally on `set()` and `save()`. Adding controller-level cache ops would duplicate logic and risk inconsistency. |
| Regex: try/catch InvalidArgumentException → ValidationException | Validate regex in controller before set | Model boot `saving` event is the source of truth for regex validation (checks named groups, compilation). Duplicating in controller would drift. Catch → convert to Inertia-friendly 422. |
| Type coercion in controller before `set()` | Send raw string to model's auto-detect | `set()` auto-detects type from PHP value type, but frontend sends strings. Controller explicitly casts: `boolean` → `filter_var($value, FILTER_VALIDATE_BOOLEAN)`, `integer` → `(int)`, `string` → `$value`. Explicit > implicit for data integrity. |
| Backend grouping: `$configs->groupBy('group')` | Frontend JS groupBy | Backend grouping is trivial (one collection method) and saves the frontend from re-grouping; also keeps the Inertia response shape intentional. |

## Data Flow

```
Admin browser                     Laravel                         Database/Cache
     │                               │                                │
     │  GET /admin/system-configs     │                                │
     │ ─────────────────────────────► │  SystemConfig::all()           │
     │                                │ ──────────────────────────────► │
     │                                │ ◄────────────────────────────── │
     │  Inertia page with             │  groupBy('group')               │
     │  { grouped: {...} }            │                                │
     │ ◄───────────────────────────── │                                │
     │                                │                                │
     │  PUT /admin/system-configs/    │                                │
     │  { value: "..." }             │                                │
     │ ─────────────────────────────► │  Validate + coerce type        │
     │                                │  SystemConfig::set()           │
     │                                │  ├─ updateOrCreate             │
     │                                │  ├─ Cache::forget()            │
     │                                │  └─ boot saving (regex check)  │
     │                                │  │                              │
     │  ◄── 302 back + flash/errors   │                                │
     │  ◄── 422 (ValidationException) │                                │
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Http/Controllers/Landlord/SystemConfigController.php` | Create | `index()`: `Inertia::render` with `SystemConfig::all()->groupBy('group')`. `update()`: validate, coerce type, `SystemConfig::set()`, catch `InvalidArgumentException` → `ValidationException`. |
| `routes/landlord.php` | Modify | +2 routes inside admin group: `GET system-configs` (index), `PUT system-configs/{systemConfig}` (update). |
| `resources/js/pages/landlord/system-configs/index.tsx` | Create | Page: grouped Cards with table rows per group, "Edit" button opens Dialog. Type-aware form: Checkbox (boolean), number (integer), textarea (regex/string description), text (default). |
| `resources/js/pages/landlord/admin-panel.tsx` | Modify | +1 card entry: `{ title: 'System Config', description: '...', icon: Settings, href: routes(...).url }`. |

## Interfaces

```php
// Controller returns to Inertia:
// { grouped: { 'payment': Collection, 'reconciliation': Collection, 'device': Collection } }

// Request: PUT /admin/system-configs/{systemConfig}
// Body: { value: string }
// Success: 302 back + flash('success'|'error')
// Error:   422 { errors: { value: [...] } }
```

## Testing Strategy

| Layer | What | Approach |
|-------|------|----------|
| Unit | SystemConfig::get/set, cache invalidation, regex validation in boot | Already covered by `tests/Unit/Services/Payment/SystemConfigTest.php`. Extend if gaps found. |
| Feature | SystemConfigController index + update | Test authenticated admin can view grouped configs; test 403 for non-admin; test update for each type (string, integer, boolean); test invalid regex returns 422 with message; test non-numeric integer returns 422. |
| Feature | Admin panel card renders | Test `GET /admin` includes SystemConfig card with correct href. |

## Migration / Rollout

No migration required. Configs are already seeded (S1). Routes use existing middleware. Rollback: revert `routes/landlord.php`, delete `SystemConfigController`, delete page component, remove card from `admin-panel.tsx`.

## Open Questions

- None — all decisions resolved by proposal, spec, and existing patterns.
