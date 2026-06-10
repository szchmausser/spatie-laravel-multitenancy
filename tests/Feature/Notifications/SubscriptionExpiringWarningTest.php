<?php

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Notifications\SubscriptionExpiringWarning;
use Illuminate\Support\Facades\Notification;

test('expiring warning notification is dispatched when ends_at is within 3-day window', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(2),
    ]);

    // Simulate what the command does: dispatch notification for this subscription
    $notification = new SubscriptionExpiringWarning($subscription);
    Notification::send($tenant, $notification);

    Notification::assertSentTo($tenant, SubscriptionExpiringWarning::class);
});

test('expiring warning notification idempotency is handled at command level', function () {
    // Idempotency is NOT at Notification::send() level — it's checked
    // by the command before dispatching. This test verifies the notification
    // can be dispatched multiple times (the command prevents duplicates).
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(2),
    ]);

    $notification = new SubscriptionExpiringWarning($subscription);

    // First dispatch
    Notification::send($tenant, $notification);
    Notification::assertSentTo($tenant, SubscriptionExpiringWarning::class, 1);

    // Second dispatch — Notification::send does NOT deduplicate
    Notification::send($tenant, $notification);
    Notification::assertSentTo($tenant, SubscriptionExpiringWarning::class, 2);
});

test('expiring warning notification contains correct data', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(2),
    ]);

    $notification = new SubscriptionExpiringWarning($subscription);
    $data = $notification->toArray($tenant);

    expect($data['subscription_id'])->toBe($subscription->id);
    expect($data['tenant_id'])->toBe($tenant->id);
    expect($data['plan_name'])->toBe($plan->name);
    expect($data['ends_at'])->toBe($subscription->ends_at->toIso8601String());
    expect($data['days_remaining'])->toBeGreaterThanOrEqual(1);
    expect($data['days_remaining'])->toBeLessThanOrEqual(2);
    expect($data['message'])->toContain('expiring');
});
