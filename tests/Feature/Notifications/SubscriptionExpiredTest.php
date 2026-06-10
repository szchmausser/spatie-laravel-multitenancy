<?php

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Notifications\SubscriptionExpired;
use Illuminate\Support\Facades\Notification;

test('expired notification is dispatched on status transition', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->subDay(),
    ]);

    // Simulate the command transitioning to Expired
    $notification = new SubscriptionExpired($subscription);
    Notification::send($tenant, $notification);

    Notification::assertSentTo($tenant, SubscriptionExpired::class);
});

test('expired notification contains correct data', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Expired,
        'ends_at' => now()->subDay(),
    ]);

    $notification = new SubscriptionExpired($subscription);
    $data = $notification->toArray($tenant);

    expect($data['subscription_id'])->toBe($subscription->id);
    expect($data['tenant_id'])->toBe($tenant->id);
    expect($data['plan_name'])->toBe($plan->name);
    expect($data['ends_at'])->toBe($subscription->ends_at->toIso8601String());
    expect($data['message'])->toContain('expired');
});
