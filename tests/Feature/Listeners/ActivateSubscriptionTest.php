<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Events\PaymentVerified;
use App\Listeners\ActivateSubscription;
use App\Models\Auth\Role;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Resource;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PaymentVerified as PaymentVerifiedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

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

test('payment verified for resource order grants entitlement to all tenant users', function () {
    Notification::fake();

    // Point tenant connection to landlord DB so makeCurrent() works in tests
    $testDatabase = config('database.connections.landlord.database');
    config(['database.connections.tenant.database' => $testDatabase]);
    DB::purge('tenant');

    $tenant = Tenant::factory()->createQuietly();
    // Override random factory database so makeCurrent() points to the shared test DB
    $tenant->updateQuietly(['database' => $testDatabase]);

    // Create minimal permission schema on the tenant connection for role assignment
    Schema::connection('tenant')->create('roles', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });
    Schema::connection('tenant')->create('model_has_roles', function ($table) {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->primary(['role_id', 'model_type', 'model_id']);
        $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
    });

    // Seed the owner role for notification targeting
    Role::create(['name' => 'owner', 'guard_name' => 'web']);

    $resource = App\Models\Resource::factory()->createQuietly();

    // Create a user on the tenant connection
    $tenantUser = User::on('tenant')->createQuietly([
        'name' => 'Tenant User',
        'email' => "user-{$tenant->id}@test.com",
        'password' => 'password',
    ]);

    // Assign owner role so the notification is dispatched to them
    $tenantUser->assignRole('owner');

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

    // Order should be paid
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Paid);

    // Should NOT create a subscription
    $subscription = Subscription::on('landlord')
        ->where('tenant_id', $tenant->id)
        ->first();
    expect($subscription)->toBeNull();

    // Should create exactly one entitlement per tenant+resource (no user loop)
    $entitlements = Entitlement::query()
        ->where('tenant_id', $tenant->id)
        ->where('resource_id', $resource->id)
        ->get();

    expect($entitlements)->toHaveCount(1);

    $entitlement = $entitlements->first();
    expect($entitlement->granted_via->value)->toBe('purchase');
    expect($entitlement->expires_at)->toBeNull();

    // Should send PaymentVerified notification to tenant admin users
    Notification::assertSentTo(
        [$tenantUser],
        PaymentVerifiedNotification::class,
    );
});

test('PaymentVerified event dispatch triggers entitlement grant via event wiring', function () {
    // Point tenant connection to landlord DB so makeCurrent() works in tests
    $testDatabase = config('database.connections.landlord.database');
    config(['database.connections.tenant.database' => $testDatabase]);
    DB::purge('tenant');

    $tenant = Tenant::factory()->createQuietly();
    $tenant->updateQuietly(['database' => $testDatabase]);

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

    $payment = Payment::where('order_id', $order->id)->first();

    // Dispatch the event — verifies AppServiceProvider registered the listener
    event(new PaymentVerified($payment));

    // Order should be paid
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Paid);

    // Should create exactly one entitlement per tenant+resource (no user loop)
    $entitlements = Entitlement::query()
        ->where('tenant_id', $tenant->id)
        ->where('resource_id', $resource->id)
        ->get();

    expect($entitlements)->toHaveCount(1);

    $entitlement = $entitlements->first();
    expect($entitlement->granted_via->value)->toBe('purchase');

    // Should NOT create a subscription
    $subscription = Subscription::on('landlord')
        ->where('tenant_id', $tenant->id)
        ->first();
    expect($subscription)->toBeNull();
});
