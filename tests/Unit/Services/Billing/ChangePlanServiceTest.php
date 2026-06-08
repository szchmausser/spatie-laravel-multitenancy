<?php

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
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
