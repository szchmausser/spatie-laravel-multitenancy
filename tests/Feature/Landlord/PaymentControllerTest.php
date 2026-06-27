<?php

use App\Enums\CancellationType;
use App\Enums\PaymentStatus;
use App\Events\PaymentCancelled;
use App\Events\PaymentVerified;
use App\Models\Landlord;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);
    $this->admin = Landlord::factory()->create();
    $this->actingAs($this->admin);
});

test('admin can verify a pending payment', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);
    $payment = Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $tenant->id,
        'status' => PaymentStatus::Pending,
    ]);

    $response = $this->post(route('landlord.payments.verify', $payment));
    $response->assertRedirect();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Verified);
    expect($payment->verified_by)->toBe($this->admin->id);
    expect($payment->verified_at)->not->toBeNull();
});

test('admin verify payment dispatches PaymentVerified event after redirect (IC-4)', function () {
    Event::fake([PaymentVerified::class]);

    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);
    $payment = Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $tenant->id,
        'status' => PaymentStatus::Pending,
    ]);

    $response = $this->post(route('landlord.payments.verify', $payment));
    $response->assertRedirect();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Verified);

    // Event should be dispatched AFTER verifyPayment (IC-4)
    Event::assertDispatched(PaymentVerified::class, fn (PaymentVerified $e) => $e->payment->id === $payment->id);
});

test('admin cancel payment dispatches PaymentCancelled event after redirect (IC-4)', function () {
    Event::fake([PaymentCancelled::class]);

    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'total_cents' => 5000,
    ]);
    $payment = Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $tenant->id,
        'amount_cents' => 5000,
        'status' => PaymentStatus::Verified,
    ]);

    $response = $this->post(route('landlord.payments.cancel', $payment), [
        'reason' => 'Suspected fraud',
    ]);
    $response->assertRedirect();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Cancelled);

    // Event should be dispatched AFTER cancelPayment (IC-4)
    Event::assertDispatched(PaymentCancelled::class, fn (PaymentCancelled $e) => $e->payment->id === $payment->id);
});

test('admin can cancel a verified payment with reason', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'total_cents' => 5000,
    ]);
    $payment = Payment::factory()->createQuietly([
        'order_id' => $order->id,
        'tenant_id' => $tenant->id,
        'amount_cents' => 5000,
        'status' => PaymentStatus::Verified,
    ]);

    $response = $this->post(route('landlord.payments.cancel', $payment), [
        'reason' => 'Suspected fraud',
    ]);
    $response->assertRedirect();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Cancelled);
    expect($payment->cancellation_type)->toBe(CancellationType::Manual);
    expect($payment->cancellation_reason)->toBe('Suspected fraud');
    expect($payment->cancelled_by)->toBe($this->admin->id);
});
