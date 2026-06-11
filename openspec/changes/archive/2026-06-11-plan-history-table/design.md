# Design: Plan History Table

## Technical Approach

Add a `subscription_history` table in the landlord DB as an append-only audit log. Each of the 4 mutation points (assign, plan-change via service, plan-change via landlord admin, expiry) calls `SubscriptionHistory::record()` inline after the primary mutation succeeds. History recording is wrapped in try/catch so failures never block the mutation. An Inertia page renders per-tenant history sorted by date.

## Architecture Decisions

### Decision: Inline recording vs. dedicated service class

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Inline `SubscriptionHistory::record()` at each call site | 4 call sites to maintain, but explicit and obvious | **Chosen** — matches proposal, keeps recording visible at mutation point |
| Dedicated `RecordSubscriptionHistory` service | Single class, but adds indirection and the service only does one thing | Rejected — over-engineering for a one-liner call |

### Decision: Snapshot denormalization vs. FK to Plan

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Copy plan fields at write time (denormalize) | Storage duplication, but history survives plan edits | **Chosen** — immutable audit trail requires this |
| FK to Plan, read at query time | No duplication, but history corrupts if Plan is edited/deleted | Rejected — defeats audit purpose |

### Decision: Nullable `actor_id`/`ip_address`/`user_agent`

| Option | Tradeoff | Decision |
|--------|----------|----------|
| All nullable | Clean, no forced context on CLI commands | **Chosen** — expiry runs from cron with no HTTP context |
| Required with sentinel values | Always populated, but pollutes data with fake values | Rejected |

## Data Flow

```
HTTP Request → Controller/Service
                    │
                    ├── 1. Primary mutation (assign/update subscription)
                    │
                    └── 2. SubscriptionHistory::record([...])  ← try/catch
                              │
                              └── INSERT INTO subscription_history (landlord DB)
```

CLI flow: `ExpireSubscriptions` → `expireOverdueSubscriptions()` → `SubscriptionHistory::record([...])` (no HTTP context, fields null).

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/landlord/2026_06_10_000002_create_subscription_history_table.php` | Create | Migration with full audit schema |
| `app/Enums/SubscriptionEventType.php` | Create | Enum with 3 values |
| `app/Models/SubscriptionHistory.php` | Create | Model with `UsesLandlordConnection` + `record()` |
| `app/Services/Billing/ChangePlanService.php` | Modify | Add `record()` call after plan mutation |
| `app/Http/Controllers/Landlord/SubscriptionController.php` | Modify | Add `record()` call after assign |
| `app/Http/Controllers/Landlord/SubscriptionChangeController.php` | Modify | Add `record()` call after plan change (landlord admin path) |
| `app/Console/Commands/ExpireSubscriptions.php` | Modify | Add `record()` call after expiry transition |
| `app/Http/Controllers/Landlord/SubscriptionHistoryController.php` | Create | History index per tenant |
| `resources/js/pages/landlord/subscriptions/history.tsx` | Create | Inertia page with sortable table |
| `routes/landlord.php` | Modify | Add history route |

## Interfaces / Contracts

### Migration Schema

```php
Schema::create('subscription_history', function (Blueprint $table) {
    $table->id();
    $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
    $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
    $table->string('event_type'); // enum stored as string
    $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('ip_address', 45)->nullable();
    $table->text('user_agent')->nullable();
    $table->text('reason')->nullable();
    // Old snapshot
    $table->string('old_plan_name')->nullable();
    $table->integer('old_plan_price_cents')->nullable();
    $table->jsonb('old_plan_features')->nullable();
    $table->string('old_status')->nullable();
    // New snapshot
    $table->string('new_plan_name')->nullable();
    $table->integer('new_plan_price_cents')->nullable();
    $table->jsonb('new_plan_features')->nullable();
    $table->string('new_status')->nullable();
    // Billing
    $table->integer('amount_cents')->nullable();
    $table->string('currency', 3)->default('USD');
    $table->timestamp('billing_period_start')->nullable();
    $table->timestamp('billing_period_end')->nullable();
    // Correlation
    $table->uuid('correlation_id')->nullable();
    $table->timestamps();

    $table->index(['tenant_id', 'created_at']);
});
```

### SubscriptionEventType Enum

```php
enum SubscriptionEventType: string
{
    case SubscriptionCreated = 'subscription_created';
    case PlanChanged = 'plan_changed';
    case SubscriptionExpired = 'subscription_expired';
}
```

### SubscriptionHistory Model — `record()` Signature

```php
public static function record(array $attributes): static
```

Accepts an associative array with all fillable columns. Returns the created model instance. The model casts `event_type` to `SubscriptionEventType`, `old_plan_features`/`new_plan_features` to `array`, `correlation_id` to `string`.

### Recording Integration Points

**1. SubscriptionController::assign()** — after `$subscription = Subscription::on('landlord')->updateOrCreate(...)`:
```php
try {
    SubscriptionHistory::record([
        'subscription_id' => $subscription->id,
        'tenant_id' => $tenant->id,
        'event_type' => SubscriptionEventType::SubscriptionCreated,
        'actor_id' => $request->user()->id,
        'ip_address' => $request->ip(),
        'user_agent' => $request->userAgent(),
        'new_plan_name' => $subscription->plan->name,
        'new_plan_price_cents' => $subscription->plan->price_cents,
        'new_plan_features' => $subscription->plan->features,
        'new_status' => $subscription->status->value,
    ]);
} catch (\Throwable $e) {
    \Log::warning('Failed to record subscription history', ['error' => $e->getMessage()]);
}
```

**2 & 3. ChangePlanService::applyPlanChange()** — after `$subscription->update(...)`:
```php
try {
    SubscriptionHistory::record([
        'subscription_id' => $subscription->id,
        'tenant_id' => $subscription->tenant_id,
        'event_type' => SubscriptionEventType::PlanChanged,
        'actor_id' => optional($request)->user()?->id, // needs Request injection
        'ip_address' => optional($request)->ip(),
        'user_agent' => optional($request)->userAgent(),
        'old_plan_name' => $oldPlan->name,
        'old_plan_price_cents' => $oldPlan->price_cents,
        'old_plan_features' => $oldPlan->features,
        'old_status' => $subscription->status->value,
        'new_plan_name' => $newPlan->name,
        'new_plan_price_cents' => $newPlan->price_cents,
        'new_plan_features' => $newPlan->features,
        'new_status' => $subscription->status->value,
        'billing_period_start' => now(),
        'billing_period_end' => now()->addMonth(),
    ]);
} catch (\Throwable $e) {
    \Log::warning('Failed to record subscription history', ['error' => $e->getMessage()]);
}
```

**Note**: `ChangePlanService` needs `Request $request` as an optional parameter (nullable, null for CLI callers). Both controllers that call it already have `$request` available — pass it through.

**4. ExpireSubscriptions::expireOverdueSubscriptions()** — after `$subscription->update(...)` inside the loop:
```php
try {
    SubscriptionHistory::record([
        'subscription_id' => $subscription->id,
        'tenant_id' => $subscription->tenant_id,
        'event_type' => SubscriptionEventType::SubscriptionExpired,
        'old_plan_name' => $subscription->plan->name,
        'old_plan_price_cents' => $subscription->plan->price_cents,
        'old_plan_features' => $subscription->plan->features,
        'old_status' => SubscriptionStatus::Active->value,
        'new_status' => SubscriptionStatus::Expired->value,
    ]);
} catch (\Throwable $e) {
    \Log::warning('Failed to record subscription history', ['error' => $e->getMessage()]);
}
```

### SubscriptionHistoryController

```php
class SubscriptionHistoryController extends Controller
{
    public function index(Tenant $tenant)
    {
        $history = SubscriptionHistory::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->with('actor')
            ->paginate(20);

        return Inertia::render('landlord/subscriptions/history', [
            'tenant' => $tenant,
            'history' => $history,
        ]);
    }
}
```

### Frontend Page Structure

`resources/js/pages/landlord/subscriptions/history.tsx`:
- Breadcrumbs: Admin → Subscriptions → {Tenant} → History
- Props: `tenant`, `history` (paginated collection)
- Table columns: Date, Event Type (badge), Old Plan, New Plan, Amount, Actor
- Empty state when no entries
- Uses existing UI components: `Card`, `Badge`, `Button`, `Link` from `@/components/ui`
- Links back to tenant show page via `show` from `@/routes/landlord/tenants`

### Route Definition

```php
Route::get('tenants/{tenant}/subscription-history', [SubscriptionHistoryController::class, 'index'])
    ->name('subscriptions.history');
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `SubscriptionHistory::record()` inserts correct row | Pest test, assert DB has row with correct values |
| Unit | `SubscriptionEventType` enum values | Pest test, assert enum cases match expected strings |
| Feature | `SubscriptionController::assign()` creates history | Call route, assert `subscription_history` row exists with `subscription_created` |
| Feature | `ChangePlanService::applyPlanChange()` creates history | Call service with Request, assert `plan_changed` row with old/new snapshots |
| Feature | `ExpireSubscriptions::expireOverdueSubscriptions()` creates history | Set up overdue subscription, run command, assert `subscription_expired` row |
| Feature | Recording failure doesn't block mutation | Mock `SubscriptionHistory::record()` to throw, assert subscription still created |
| Feature | History index page scoped to tenant | Create history for 2 tenants, assert only correct tenant shown |
| Feature | History index empty state | Assert page renders for tenant with no history |
| Unit | Snapshot immutability | Record history, then rename plan, assert history still shows old name |

**TDD Order**: enum → model + record() → assign recording → plan-change recording → expiry recording → failure resilience → controller → page

## Migration / Rollout

Single migration, no data backfill. New table only. No feature flags needed — the feature is additive and invisible until the route is used.

## Open Questions

- [x] Should `ChangePlanService` accept `?Request $request` as a new parameter, or should the controllers call `SubscriptionHistory::record()` directly after calling the service? **DECIDED: Option A** — service records internally with nullable Request parameter. Controllers pass `$request` through. Cleaner snapshot resolution, recording happens where the mutation lives.
