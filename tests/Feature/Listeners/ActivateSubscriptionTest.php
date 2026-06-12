<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Events\PaymentVerified;
use App\Listeners\ActivateSubscription;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Resource;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Facades\Event;

test('payment verified + order fully paid creates subscription', function () {
    Event::fake([PaymentVerified::class]);

    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'total_cents' => 1000,
        'status' => OrderStatus::Pending,
    ]);

    Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $tenant->id,
        'amount_cents' => 1000,
        'status' => PaymentStatus::Verified,
    ]);

    $listener = new ActivateSubscription;
    $payment = Payment::where('order_id', $order->id)->first();

    // Manually dispatch the listener since Event::fake prevents it
    $listener->handle(new PaymentVerified($payment));

    $subscription = Subscription::on('landlord')
        ->where('tenant_id', $tenant->id)
        ->first();

    expect($subscription)->not->toBeNull();
    expect($subscription->plan_id)->toBe($plan->id);
    expect($subscription->status)->toBe(SubscriptionStatus::Active);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Paid);
});

test('payment verified + not fully paid does not create subscription', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'total_cents' => 5000,
        'status' => OrderStatus::Pending,
    ]);

    Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $tenant->id,
        'amount_cents' => 2000,
        'status' => PaymentStatus::Verified,
    ]);

    $listener = new ActivateSubscription;
    $payment = Payment::where('order_id', $order->id)->first();
    $listener->handle(new PaymentVerified($payment));

    $subscription = Subscription::on('landlord')
        ->where('tenant_id', $tenant->id)
        ->first();

    expect($subscription)->toBeNull();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Pending);
});

test('payment verified + already has active subscription updates it', function () {
    $tenant = Tenant::factory()->createQuietly();
    $oldPlan = Plan::factory()->createQuietly();
    $newPlan = Plan::factory()->createQuietly();

    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $oldPlan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $newPlan->id,
        'total_cents' => 1000,
        'status' => OrderStatus::Pending,
    ]);

    Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $tenant->id,
        'amount_cents' => 1000,
        'status' => PaymentStatus::Verified,
    ]);

    $listener = new ActivateSubscription;
    $payment = Payment::where('order_id', $order->id)->first();
    $listener->handle(new PaymentVerified($payment));

    // Should update, not duplicate
    $count = Subscription::on('landlord')
        ->where('tenant_id', $tenant->id)
        ->count();

    expect($count)->toBe(1);

    $subscription = Subscription::on('landlord')
        ->where('tenant_id', $tenant->id)
        ->first();

    expect($subscription->plan_id)->toBe($newPlan->id);
    expect($subscription->status)->toBe(SubscriptionStatus::Active);
});

test('payment verified for resource order does not create subscription', function () {
    $tenant = Tenant::factory()->createQuietly();
    $resource = App\Models\Resource::factory()->createQuietly();
    $order = Order::factory()->forResource()->createQuietly([
        'tenant_id' => $tenant->id,
        'resource_id' => $resource->id,
        'total_cents' => 1000,
        'status' => OrderStatus::Pending,
    ]);

    Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $tenant->id,
        'amount_cents' => 1000,
        'status' => PaymentStatus::Verified,
    ]);

    $listener = new ActivateSubscription;
    $payment = Payment::where('order_id', $order->id)->first();
    $listener->handle(new PaymentVerified($payment));

    $subscription = Subscription::on('landlord')
        ->where('tenant_id', $tenant->id)
        ->first();

    expect($subscription)->toBeNull();

    // Order should still be paid (resource order, not subscription)
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Paid);
});
