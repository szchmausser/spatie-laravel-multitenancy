# Design: Notification Ingest API

## Technical Approach

Extract the notification entry point from `DeviceController::storeNotification()` into a dedicated `IngestController` + `IngestNotificationAction`, and rename the route from `POST /api/notifications` to `POST /api/ingest/bank-app`. The source type segment (`bank-app`) drives `SourceType` resolution via `tryFrom()`. This decouples each notification source into its own URL namespace without branching in the controller.

## Architecture Decisions

### Decision: Route structure

| Option | Tradeoff | Decision |
|--------|----------|----------|
| `POST /api/ingest/{source}` with controller resolution | Single route, 422 for unknown source | **Chosen** — extensible, no route changes for new sources |
| `POST /api/ingest/{source}` with `->whereIn()` | Early 404 vs 422 ambiguity | Rejected — spec requires 422 for unrecognized sources |
| One route per source | Route bloat per new source | Rejected — repetitive |

### Decision: Controller shape

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Single `__invoke` | One method, no naming ambiguity | **Chosen** — the controller has exactly one job |
| Named `store()` | Works but implies more methods | Rejected — over-generic name |

### Decision: Migration amendment

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Modify existing migration | Clean history if not deployed | **Chosen** — migration created this session, no production data |
| New migration with update | Needed if already deployed | Fallback — create `update_source_type_default.php` |

## Data Flow

```
Device ──POST /api/ingest/{source}──→ IngestController::__invoke()
                                             │
                                    SourceType::tryFrom($source)
                                             │
                                   (null → 422, found → proceed)
                                             │
                                    $request->validate(['bank_code', 'raw_body'])
                                             │
                                    ┌────────▼────────────┐
                                    │ IngestNotification   │
                                    │ Action::__invoke()   │
                                    │                      │
                                    │ computeDedupHash()   │
                                    │ forceCreate()        │
                                    │ dispatch(job)        │
                                    │ return notification   │
                                    └────────┬────────────┘
                                             │
                              ┌─ success ────┤
                              │              │ (QueryException 23505)
                        201 created    200 duplicate_ignored
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Actions/IngestNotificationAction.php` | Create | Shared action: computeHash → forceCreate → dispatch job → return notification |
| `app/Http/Controllers/Api/IngestController.php` | Create | Single `__invoke`: resolve SourceType from URL, validate, delegate, catch 23505 |
| `app/Enums/SourceType.php` | Modify | `AndroidPush` → `BankApp`, value `android_push` → `bank-app`, update `label()` |
| `app/Http/Controllers/Api/DeviceController.php` | Modify | Remove `storeNotification()` method |
| `routes/api.php` | Modify | Remove old route; add `POST /ingest/{source}` under `device.auth` |
| `database/migrations/landlord/2026_07_03_000001_add_source_type_to_payment_notifications.php` | Modify | Default `android_push` → `bank-app`, backfill value same |
| `tests/Feature/Api/DeviceNotificationTest.php` | Modify | Update URL, source_type assertion; add 422 and 404 tests |

## Interfaces / Contracts

### IngestNotificationAction

```php
class IngestNotificationAction
{
    public function __invoke(
        Device $device,
        string $bankCode,
        string $rawBody,
        SourceType $sourceType,
    ): PaymentNotification;
}
```

### IngestController::__invoke

```php
// Route parameter {source} is resolved by Laravel's router
public function __invoke(Request $request, string $source): JsonResponse;
```

- Device retrieved from `$request->get('device')` (injected by `device.auth` middleware)
- Source resolved via `SourceType::tryFrom($source)` — returns 422 if null
- 23505 caught → 200 `{ "status": "duplicate_ignored" }`
- Success → 201 `{ "status": "created" }`

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Feature | Happy path 201 | POST valid payload → assert DB row + 201 |
| Feature | Duplicate dedup | Same payload twice → first 201, second 200 `duplicate_ignored` |
| Feature | Invalid source | POST `/api/ingest/garbage` → 422 |
| Feature | Old route 404 | POST `/api/notifications` → 404 |
| Feature | SourceType value persisted | Assert `source_type` = `bank-app` in DB |

## Migration / Rollout

The existing `2026_07_03_000001_add_source_type_to_payment_notifications.php` migration is amended in-place (created this session, no production deployment). The default and backfill value change from `android_push` to `bank-app`. Rollback: `git revert` on the change commits.

## Open Questions

None.
