<?php

use App\Enums\ActorType;
use App\Enums\SubscriptionStatus;
use App\Models\Landlord;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use App\Services\Billing\ChangePlanService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Unit tests for {@see ChangePlanService}.
 *
 * Pins the contract of the shared mutation that backs both the
 * tenant-side self-service flow (Billing\PlanChangeController) and
 * the landlord backdoor (Landlord\SubscriptionChangeController). Both
 * controllers are thin auth + resolution wrappers; the same-plan
 * guard and the ends_at reset live HERE.
 */
beforeEach(function () {
    $testDatabase = config('database.connections.landlord.database');

    config(['database.connections.tenant.database' => $testDatabase]);
    DB::purge('tenant');
});

test('applyPlanChange updates plan_id and resets ends_at', function () {
    $oldPlan = Plan::factory()->createQuietly();
    $newPlan = Plan::factory()->createQuietly();
    $tenant = Tenant::factory()->createQuietly();
    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $oldPlan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => null,
    ]);

    Carbon::setTestNow('2026-06-15 12:00:00');
    try {
        app(ChangePlanService::class)->applyPlanChange($subscription, $newPlan);

        $subscription->refresh();
        expect($subscription->plan_id)->toBe($newPlan->id)
            ->and($subscription->ends_at?->toDateTimeString())->toBe('2026-07-15 12:00:00');
    } finally {
        Carbon::setTestNow();
    }
});

test('applyPlanChange does not modify trial_ends_at', function () {
    $oldPlan = Plan::factory()->createQuietly();
    $newPlan = Plan::factory()->createQuietly();
    $tenant = Tenant::factory()->createQuietly();
    $trialEnd = Carbon::parse('2026-12-31 23:59:59');
    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $oldPlan->id,
        'status' => SubscriptionStatus::Trialing,
        'trial_ends_at' => $trialEnd,
        'ends_at' => null,
    ]);

    app(ChangePlanService::class)->applyPlanChange($subscription, $newPlan);

    $subscription->refresh();
    expect($subscription->trial_ends_at?->toDateTimeString())->toBe('2026-12-31 23:59:59');
});

test('applyPlanChange throws 422 when the requested plan is already active', function () {
    $plan = Plan::factory()->createQuietly();
    $tenant = Tenant::factory()->createQuietly();
    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => null,
    ]);

    app(ChangePlanService::class)->applyPlanChange($subscription, $plan);
})->throws(HttpException::class, 'You are already on this plan.');

test('applyPlanChange records system actor when no request', function () {
    $oldPlan = Plan::factory()->createQuietly();
    $newPlan = Plan::factory()->createQuietly();
    $tenant = Tenant::factory()->createQuietly();
    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $oldPlan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => null,
    ]);

    app(ChangePlanService::class)->applyPlanChange($subscription, $newPlan);

    $history = SubscriptionHistory::where('tenant_id', $tenant->id)
        ->where('event_type', 'plan_changed')
        ->first();

    expect($history)->not->toBeNull();
    expect($history->actor_type)->toBe(ActorType::System);
    expect($history->actor_name)->toBe('System');
    expect($history->actor_id)->toBeNull();
    expect($history->actor_email)->toBeNull();
});

test('applyPlanChange records landlord actor when request has Landlord user', function () {
    $oldPlan = Plan::factory()->createQuietly();
    $newPlan = Plan::factory()->createQuietly();
    $tenant = Tenant::factory()->createQuietly();
    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $oldPlan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => null,
    ]);

    $admin = Landlord::factory()->create();
    $request = request()->setUserResolver(fn () => $admin);

    app(ChangePlanService::class)->applyPlanChange($subscription, $newPlan, $request);

    $history = SubscriptionHistory::where('tenant_id', $tenant->id)
        ->where('event_type', 'plan_changed')
        ->first();

    expect($history)->not->toBeNull();
    expect($history->actor_type)->toBe(ActorType::Landlord);
    expect($history->actor_name)->toBe($admin->name);
    expect($history->actor_email)->toBe($admin->email);
    expect($history->actor_id)->toBe($admin->id);
});

test('applyPlanChange records amount_cents, currency, and correlation_id', function () {
    $oldPlan = Plan::factory()->createQuietly(['price_cents' => 2900]);
    $newPlan = Plan::factory()->createQuietly(['price_cents' => 9900]);
    $tenant = Tenant::factory()->createQuietly();
    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $oldPlan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => null,
    ]);

    app(ChangePlanService::class)->applyPlanChange($subscription, $newPlan);

    $history = SubscriptionHistory::where('tenant_id', $tenant->id)
        ->where('event_type', 'plan_changed')
        ->first();

    expect($history->amount_cents)->toBe(9900);
    expect($history->currency)->toBe('USD');
    expect($history->correlation_id)->not->toBeNull();
});

test('applyPlanChange records reason when provided', function () {
    $oldPlan = Plan::factory()->createQuietly();
    $newPlan = Plan::factory()->createQuietly();
    $tenant = Tenant::factory()->createQuietly();
    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $oldPlan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => null,
    ]);

    $request = request()->merge(['reason' => 'Upgrade for Q4']);

    app(ChangePlanService::class)->applyPlanChange($subscription, $newPlan, $request);

    $history = SubscriptionHistory::where('tenant_id', $tenant->id)
        ->where('event_type', 'plan_changed')
        ->first();

    expect($history->reason)->toBe('Upgrade for Q4');
});

test('applyPlanChange records null reason when not provided', function () {
    $oldPlan = Plan::factory()->createQuietly();
    $newPlan = Plan::factory()->createQuietly();
    $tenant = Tenant::factory()->createQuietly();
    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $oldPlan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => null,
    ]);

    app(ChangePlanService::class)->applyPlanChange($subscription, $newPlan);

    $history = SubscriptionHistory::where('tenant_id', $tenant->id)
        ->where('event_type', 'plan_changed')
        ->first();

    expect($history->reason)->toBeNull();
});
