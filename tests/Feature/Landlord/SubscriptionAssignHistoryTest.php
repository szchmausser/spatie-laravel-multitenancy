<?php

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

test('assigning a plan records subscription_created history with actor context', function () {
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly(['name' => 'Premium', 'price_cents' => 9900]);

    $this->post(route('landlord.subscriptions.assign', $tenant), [
        'plan_id' => $plan->id,
    ]);

    $history = SubscriptionHistory::where('tenant_id', $tenant->id)->first();
    expect($history)->not->toBeNull();
    expect($history->event_type)->toBe(SubscriptionEventType::SubscriptionCreated);
    expect($history->actor_id)->toBe($this->admin->id);
    expect($history->ip_address)->not->toBeNull();
    expect($history->user_agent)->not->toBeNull();
    expect($history->old_plan_name)->toBeNull();
    expect($history->new_plan_name)->toBe('Premium');
    expect($history->new_plan_price_cents)->toBe(9900);
    expect($history->new_status)->toBe(SubscriptionStatus::Active->value);
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
});
