# Tasks: Plan History Table

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~430 |
| 400-line budget risk | Medium |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | exception-ok |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Medium

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Full plan history feature | PR 1 | Self-contained: enum, migration, model, recording, controller, page, tests |

## Phase 1: Foundation

- [x] 1.1 Create `app/Enums/SubscriptionEventType.php` — 3-case string enum: `SubscriptionCreated`, `PlanChanged`, `SubscriptionExpired` with `label()` method
- [x] 1.2 Create `database/migrations/landlord/2026_06_10_000002_create_subscription_history_table.php` — full audit schema per design, composite index on `(tenant_id, created_at)`

## Phase 2: Model

- [x] 2.1 Create `app/Models/SubscriptionHistory.php` — `UsesLandlordConnection`, `HasFactory`, `record()` static method, casts for `event_type`, `*_plan_features` → array, `correlation_id` → string. Fillable: all non-auto-generated columns
- [x] 2.2 **RED**: Create `tests/Feature/Models/SubscriptionHistoryTest.php` — test `record()` inserts a row, test enum cast, test landlord connection. **GREEN**: Implement model

## Phase 3: Recording Integration

- [x] 3.1 Modify `app/Services/Billing/ChangePlanService.php` — change signature to `applyPlanChange(Subscription $subscription, Plan $newPlan, ?Request $request = null)`, snapshot old plan state before mutation, record `PlanChanged` entry after `$subscription->update()`, wrapped in try/catch
- [x] 3.2 Modify `app/Http/Controllers/Landlord/SubscriptionChangeController.php` — pass `$request` as third arg to `$service->applyPlanChange()`
- [x] 3.3 Modify `app/Http/Controllers/Billing/PlanChangeController.php` — pass `$request` as third arg to `$service->applyPlanChange()`
- [x] 3.4 Modify `app/Http/Controllers/Landlord/SubscriptionController::assign()` — record `SubscriptionCreated` entry after `updateOrCreate()`, with actor/ip/user-agent, wrapped in try/catch
- [x] 3.5 Modify `app/Console/Commands/ExpireSubscriptions.php` — record `SubscriptionExpired` entry inside the loop after `$subscription->update()`, no actor/ip/user-agent (CLI), wrapped in try/catch
- [x] 3.6 **RED**: Add tests to `tests/Feature/Billing/PlanChangeControllerTest.php` — assert `subscription_history` row with `plan_changed`, old/new snapshots, actor from request
- [x] 3.7 **RED**: Add test to `tests/Feature/Console/ExpireSubscriptionsTest.php` — assert `subscription_expired` row after expire command, verify null actor/ip/user-agent
- [x] 3.8 **RED**: Create `tests/Feature/Landlord/SubscriptionAssignHistoryTest.php` — assert `subscription_created` row after assign, verify actor/ip/user-agent populated

## Phase 4: Controller & Route

- [x] 4.1 Create `app/Http/Controllers/Landlord/SubscriptionHistoryController.php` — `index(Tenant $tenant)` queries `SubscriptionHistory::where('tenant_id', $tenant->id)->orderByDesc('created_at')->paginate(20)`, renders `landlord/subscriptions/history` Inertia page
- [x] 4.2 Add route to `routes/landlord.php` — `GET tenants/{tenant}/subscription-history` → `SubscriptionHistoryController@index`, name `subscriptions.history`
- [x] 4.3 **RED**: Create `tests/Feature/Landlord/SubscriptionHistoryControllerTest.php` — test page renders with history entries scoped to tenant, test empty state, test tenant isolation

## Phase 5: Frontend

- [x] 5.1 Create `resources/js/pages/landlord/subscriptions/history.tsx` — Inertia page with breadcrumbs, table (date, event type badge, old plan, new plan, amount, actor), empty state, pagination. Props: `tenant`, `history`

## Phase 6: Final Verification

- [x] 6.1 Run `php artisan test --compact` — all tests pass
- [x] 6.2 Run `vendor/bin/pint --dirty --format agent` — code formatted
