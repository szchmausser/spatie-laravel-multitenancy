<?php

use App\Enums\ActorType;
use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Models\Landlord;
use App\Models\Plan;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

beforeEach(function () {
    $this->withoutMiddleware(VerifyCsrfToken::class);

    $this->admin = Landlord::factory()->create();
    $this->actingAs($this->admin);
});

test('assigning a plan records subscription_created history with actor snapshot', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly(['name' => 'Premium', 'price_cents' => 9900]);

    $this->post(route('landlord.subscriptions.assign', $tenant), [
        'plan_id' => $plan->id,
    ]);

    $history = SubscriptionHistory::where('tenant_id', $tenant->id)->first();
    expect($history)->not->toBeNull();
    expect($history->event_type)->toBe(SubscriptionEventType::SubscriptionCreated);
    expect($history->actor_id)->toBe($this->admin->id);
    expect($history->actor_name)->toBe($this->admin->name);
    expect($history->actor_email)->toBe($this->admin->email);
    expect($history->actor_type)->toBe(ActorType::Landlord);
    expect($history->ip_address)->not->toBeNull();
    expect($history->user_agent)->not->toBeNull();
    expect($history->old_plan_name)->toBeNull();
    expect($history->new_plan_name)->toBe('Premium');
    expect($history->new_plan_price_cents)->toBe(9900);
    expect($history->new_status)->toBe(SubscriptionStatus::Active->value);
    expect($history->billing_period_start)->not->toBeNull();
    expect($history->billing_period_end)->not->toBeNull();
    expect($history->billing_period_end->greaterThan(now()))->toBeTrue();
});

test('assigning a plan records history with old snapshot null (no previous state)', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly(['name' => 'Basic', 'price_cents' => 2900]);

    $this->post(route('landlord.subscriptions.assign', $tenant), [
        'plan_id' => $plan->id,
    ]);

    $history = SubscriptionHistory::where('tenant_id', $tenant->id)->first();
    expect($history->old_plan_name)->toBeNull();
    expect($history->old_plan_price_cents)->toBeNull();
    expect($history->old_plan_features)->toBeNull();
    expect($history->old_status)->toBeNull();
    expect($history->billing_period_start)->not->toBeNull();
    expect($history->billing_period_end)->not->toBeNull();
});

test('changing plan via assign endpoint records plan_changed with old snapshot', function () {
    $tenant = Tenant::factory()->createQuietly();
    $oldPlan = Plan::factory()->createQuietly(['name' => 'Basic', 'price_cents' => 2900]);
    $newPlan = Plan::factory()->createQuietly(['name' => 'Premium', 'price_cents' => 9900]);

    // First assign
    $this->post(route('landlord.subscriptions.assign', $tenant), [
        'plan_id' => $oldPlan->id,
    ]);

    // Change plan via same endpoint
    $this->post(route('landlord.subscriptions.assign', $tenant), [
        'plan_id' => $newPlan->id,
    ]);

    $history = SubscriptionHistory::where('tenant_id', $tenant->id)
        ->where('event_type', SubscriptionEventType::PlanChanged)
        ->first();

    expect($history)->not->toBeNull();
    expect($history->old_plan_name)->toBe('Basic');
    expect($history->old_plan_price_cents)->toBe(2900);
    expect($history->new_plan_name)->toBe('Premium');
    expect($history->new_plan_price_cents)->toBe(9900);
    expect($history->actor_id)->toBe($this->admin->id);
    expect($history->actor_name)->toBe($this->admin->name);
    expect($history->actor_email)->toBe($this->admin->email);
    expect($history->actor_type)->toBe(ActorType::Landlord);
    expect($history->amount_cents)->toBe(9900);
    expect($history->currency)->toBe('USD');
    expect($history->correlation_id)->not->toBeNull();
    expect($history->billing_period_start)->not->toBeNull();
    expect($history->billing_period_end)->not->toBeNull();
    expect($history->billing_period_end->greaterThan(now()))->toBeTrue();
});

test('assign endpoint records amount_cents and currency for new subscription', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly(['name' => 'Basic', 'price_cents' => 2900]);

    $this->post(route('landlord.subscriptions.assign', $tenant), [
        'plan_id' => $plan->id,
    ]);

    $history = SubscriptionHistory::where('tenant_id', $tenant->id)->first();
    expect($history->amount_cents)->toBe(2900);
    expect($history->currency)->toBe('USD');
    expect($history->correlation_id)->not->toBeNull();
});

test('assign endpoint records optional reason', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    $this->post(route('landlord.subscriptions.assign', $tenant), [
        'plan_id' => $plan->id,
        'reason' => 'Customer requested upgrade',
    ]);

    $history = SubscriptionHistory::where('tenant_id', $tenant->id)->first();
    expect($history->reason)->toBe('Customer requested upgrade');
});

test('assign endpoint works without reason', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();

    $this->post(route('landlord.subscriptions.assign', $tenant), [
        'plan_id' => $plan->id,
    ]);

    $history = SubscriptionHistory::where('tenant_id', $tenant->id)->first();
    expect($history->reason)->toBeNull();
});
