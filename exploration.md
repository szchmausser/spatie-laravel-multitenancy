# Exploration: Phase 2 Payment Gateway Implementation

## Current State

### Existing Architecture
- **Plan** model (landlord): id, name, slug, price_cents, currency, features, is_active, is_default
- **Subscription** model (tenant): id, tenant_id, plan_id, status (active/trialing/cancelled/expired), starts_at, ends_at
- **SubscriptionHistory** model (landlord): full audit trail with actor snapshots
- **ChangePlanService**: handles plan changes but NO reactivation of expired subscriptions
- **BuyResourceDialog**: simulated purchase flow with Phase 2 marker
- **ResourceController::request()**: creates entitlement on the fly (simulated purchase)

### Key Gaps for Phase 2
1. **No Order/Payment model** — only simulated purchases exist
2. **No payment gateway abstraction** — just direct entitlement creation
3. **No subscription reactivation** — expired subscriptions stay expired
4. **No idempotency** — double-clicks could create duplicate entitlements
5. **No refund handling** — no mechanism to revoke access

## Affected Areas

- `app/Services/Billing/ChangePlanService.php` — needs reactivation logic for expired→active
- `resources/js/components/resources/buy-resource-dialog.tsx` — Phase 2 marker for gateway swap
- `app/Http/Controllers/Resource/ResourceController.php` — simulated purchase needs real payment flow
- `app/Enums/SubscriptionStatus.php` — may need `Suspended` state
- `app/Enums/SubscriptionEventType.php` — needs payment-related events

## Approaches

### 1. **Pago Móvil Manual (Local)**
- **Description**: Manual verification of bank transfers via Pago Móvil (Venezuelan payment method)
- **Pros**:
  - Zero external dependencies
  - No transaction fees (bank-to-bank)
  - Familiar to Venezuelan users
  - MVP speed — prove flow end-to-end
- **Cons**:
  - Manual verification required (admin overhead)
  - No real-time confirmation
  - Doesn't scale internationally
  - User experience: pending state until admin verifies
- **Effort**: Low

### 2. **Stripe/Cashier (International)**
- **Description**: Full integration with Stripe Payment Intents + Laravel Cashier
- **Pros**:
  - Real-time payment confirmation
  - Automatic webhook handling
  - Built-in subscription management
  - International credit card support
  - Professional UX
- **Cons**:
  - Transaction fees (2.9% + $0.30 per transaction)
  - Requires Stripe account setup
  - More complex implementation
  - Not ideal for Venezuelan local payments
- **Effort**: High

### 3. **Hybrid Approach (Recommended)**
- **Description**: Manual Pago Móvil first, then Stripe as optional gateway
- **Pros**:
  - MVP with local payments (low effort)
  - Strategy pattern allows adding Stripe later
  - Proves flow end-to-end before external complexity
  - User can choose payment method
- **Cons**:
  - Two gateways to maintain
  - Stripe integration still needed for international users
- **Effort**: Medium (Phase 2A: Manual, Phase 2B: Stripe)

## Recommendation

**Hybrid Approach with Manual-First Strategy**

1. **Phase 2A**: Manual Pago Móvil gateway
   - PaymentGatewayInterface with ManualPaymentGateway
   - PaymentService orchestrates flow
   - Admin verification UI for pending payments
   - Subscription reactivation on payment verified

2. **Phase 2B**: Stripe integration (optional)
   - StripePaymentGateway implements same interface
   - Webhook handling for real-time confirmation
   - Proration and mid-cycle billing

## Model Design

### New Models Needed

```php
// Payment (landlord connection)
Payment {
    id, tenant_id, subscription_id, plan_id,
    amount_cents, currency, status (pending/verified/rejected/refunded),
    payment_method, payment_reference, metadata,
    verified_by, verified_at, correlation_id
}

// Refund (landlord connection) - optional for Phase 2A
Refund {
    id, payment_id, amount_cents, reason,
    status (pending/completed/failed),
    processed_by, processed_at, correlation_id
}
```

### Database Schema

```sql
-- landlord DB
CREATE TABLE payments (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT REFERENCES tenants(id),
    subscription_id BIGINT REFERENCES subscriptions(id),
    plan_id BIGINT REFERENCES plans(id),
    amount_cents BIGINT NOT NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    payment_method VARCHAR(50),
    payment_reference VARCHAR(255) UNIQUE,
    metadata JSONB,
    verified_by BIGINT REFERENCES landlords(id),
    verified_at TIMESTAMP,
    correlation_id UUID NOT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

CREATE TABLE refunds (
    id BIGSERIAL PRIMARY KEY,
    payment_id BIGINT REFERENCES payments(id),
    amount_cents BIGINT NOT NULL,
    reason TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    processed_by BIGINT REFERENCES landlords(id),
    processed_at TIMESTAMP,
    correlation_id UUID NOT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

## Subscription Reactivation Flow

### Current Behavior
- `ChangePlanService::applyPlanChange()` — does NOT reactivate expired subscriptions
- Expired subscriptions stay expired even if plan changes

### Proposed Changes

```php
// ChangePlanService.php
public function applyPlanChange(Subscription $subscription, Plan $newPlan, ?Request $request = null): void
{
    abort_if(
        $subscription->plan_id === $newPlan->id,
        422,
        'You are already on this plan.',
    );

    $oldPlan = $subscription->plan;
    $oldStatus = $subscription->status;

    // NEW: Handle reactivation for expired subscriptions
    $newStatus = $this->resolveNewStatus($subscription, $newPlan);

    $subscription->update([
        'plan_id' => $newPlan->id,
        'status' => $newStatus,
        'ends_at' => now()->addMonth(),
    ]);

    // Record history entry with new status
    SubscriptionHistory::record([
        // ... existing fields ...
        'old_status' => $oldStatus->value,
        'new_status' => $newStatus->value,
        // ...
    ]);
}

private function resolveNewStatus(Subscription $subscription, Plan $newPlan): SubscriptionStatus
{
    // If subscription is expired and new plan is paid, require payment first
    if ($subscription->status === SubscriptionStatus::Expired && $newPlan->price_cents > 0) {
        // Return current status — payment will activate later
        return $subscription->status;
    }

    // If subscription is expired and new plan is free, reactivate immediately
    if ($subscription->status === SubscriptionStatus::Expired && $newPlan->price_cents === 0) {
        return SubscriptionStatus::Active;
    }

    // Default: keep current status
    return $subscription->status;
}
```

### Payment Verification Flow

```php
// PaymentService.php
public function verifyPayment(Payment $payment, Landlord $verifier): Payment
{
    $payment->update([
        'status' => PaymentStatus::Verified,
        'verified_by' => $verifier->id,
        'verified_at' => now(),
    ]);

    // Dispatch event for subscription activation
    event(new PaymentVerified($payment));

    return $payment;
}

// ActivateSubscription listener
public function handle(PaymentVerified $event): void
{
    $payment = $event->payment;
    $tenant = $payment->tenant;
    $plan = $payment->plan;

    // Find or create subscription
    $subscription = Subscription::firstOrCreate(
        ['tenant_id' => $tenant->id],
        [
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'ends_at' => now()->addMonth(),
        ]
    );

    // If subscription exists but is expired, reactivate
    if ($subscription->status === SubscriptionStatus::Expired) {
        $subscription->update([
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'ends_at' => now()->addMonth(),
        ]);
    }
}
```

## Idempotency Strategy

### Prevention Layers

1. **Database Constraint**: `UNIQUE(payment_reference)` on payments table
2. **Application Check**: `Payment::where('payment_reference', $reference)->exists()` before create
3. **Frontend**: Disable button during processing (already implemented in BuyResourceDialog)

### Implementation

```php
// PaymentService.php
public function initiatePayment(Tenant $tenant, Plan $plan, string $paymentReference): Payment
{
    // Check for existing payment with same reference
    $existing = Payment::where('payment_reference', $paymentReference)->first();
    if ($existing) {
        return $existing; // Idempotent return
    }

    return Payment::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'amount_cents' => $plan->price_cents,
        'currency' => 'USD',
        'status' => PaymentStatus::Pending,
        'payment_method' => 'pago_movil',
        'payment_reference' => $paymentReference,
        'correlation_id' => Str::uuid(),
    ]);
}
```

## Refund Handling

### Full Refund
- Revokes access immediately
- Sets subscription status to `cancelled`
- Records refund in refunds table
- Sends notification to tenant

### Partial Refund
- Does not affect subscription status (keeps active)
- Records partial refund amount
- Useful for billing disputes

### Implementation

```php
// PaymentService.php
public function processRefund(Payment $payment, int $amountCents, string $reason, Landlord $processor): Refund
{
    abort_if($amountCents > $payment->amount_cents, 422, 'Refund exceeds payment amount');

    $refund = Refund::create([
        'payment_id' => $payment->id,
        'amount_cents' => $amountCents,
        'reason' => $reason,
        'status' => 'completed',
        'processed_by' => $processor->id,
        'processed_at' => now(),
        'correlation_id' => Str::uuid(),
    ]);

    // If full refund, revoke access
    if ($amountCents === $payment->amount_cents) {
        $this->revokeAccess($payment->tenant, $payment->plan);
    }

    return $refund;
}

private function revokeAccess(Tenant $tenant, Plan $plan): void
{
    $subscription = $tenant->subscription;
    if ($subscription && $subscription->plan_id === $plan->id) {
        $subscription->update([
            'status' => SubscriptionStatus::Cancelled,
            'ends_at' => now(), // Immediate cancellation
        ]);
    }
}
```

## Implementation Phases

### Phase 2A: Manual Pago Móvil Gateway (MVP)
**Duration**: 1-2 weeks
**Scope**:
1. Create `Payment` model + migration
2. Create `PaymentStatus` enum
3. Create `PaymentGatewayInterface`
4. Create `ManualPaymentGateway`
5. Create `PaymentService`
6. Create `PaymentVerified` event
7. Create `ActivateSubscription` listener
8. Create admin payment verification UI
9. Update `ChangePlanService` for reactivation
10. Write Pest tests for each layer

**Deliverables**:
- Tenants can initiate payment (creates pending payment)
- Admins can verify/reject payments
- Subscriptions activate on payment verification
- Expired subscriptions can be reactivated

### Phase 2B: Stripe Integration (Optional)
**Duration**: 2-3 weeks
**Scope**:
1. Create `StripePaymentGateway` implementing `PaymentGatewayInterface`
2. Add Stripe webhook handling
3. Implement proration for mid-cycle changes
4. Add automatic payment retry
5. Implement dunning management

**Deliverables**:
- Real-time credit card payments
- Automatic subscription activation
- Webhook-driven status updates
- Proration calculations

### Phase 2C: Refunds & Advanced Features
**Duration**: 1 week
**Scope**:
1. Create `Refund` model + migration
2. Implement refund processing
3. Add refund notifications
4. Implement access revocation on full refund

**Deliverables**:
- Full/partial refund processing
- Automatic access revocation
- Refund audit trail

## Risks

1. **Manual Verification Overhead**: Pago Móvil requires admin time to verify payments
2. **Payment Delays**: Manual verification creates pending state UX
3. **Stripe Complexity**: Webhook handling and subscription management adds complexity
4. **Venezuelan Banking**: Pago Móvil may have restrictions or delays

## Ready for Proposal

**Yes** — the hybrid approach with manual-first strategy is recommended. This allows:
1. Quick MVP with local payments
2. Proves flow end-to-end before external complexity
3. Strategy pattern enables future Stripe integration
4. Meets Venezuelan user needs while keeping international option open

The orchestrator should present this analysis to the user and confirm the hybrid approach before proceeding to proposal phase.
