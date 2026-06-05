<?php

use App\Enums\SubscriptionStatus;
use App\Http\Middleware\EnsureTenantHasFeature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

test('middleware blocks tenant without premium feature', function () {
    // Create a tenant with a subscription that does NOT have premium-zone
    $tenant = Tenant::factory()->createQuietly();

    $plan = Plan::factory()->createQuietly([
        'features' => ['premium-zone' => false],
    ]);

    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    // Make the tenant current
    $tenant->makeCurrent();

    // Create middleware and request
    $middleware = new EnsureTenantHasFeature;
    $request = Request::create('/test', 'GET');

    // The middleware should abort with 403
    try {
        $middleware->handle($request, fn () => new Response('success'), 'premium-zone');
        $this->fail('Expected abort exception was not thrown.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
        expect($e->getMessage())->toContain('does not include this feature');
    }
});

test('middleware allows tenant with matching feature', function () {
    // Create a tenant with a subscription that HAS premium-zone
    $tenant = Tenant::factory()->createQuietly();

    $plan = Plan::factory()->createQuietly([
        'features' => ['premium-zone' => true],
    ]);

    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    // Make the tenant current
    $tenant->makeCurrent();

    // Create middleware and request
    $middleware = new EnsureTenantHasFeature;
    $request = Request::create('/test', 'GET');

    // The middleware should pass through
    $response = $middleware->handle($request, fn () => new Response('success'), 'premium-zone');

    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toBe('success');
});

test('middleware blocks without tenant context', function () {
    // Create middleware and request without setting a current tenant
    $middleware = new EnsureTenantHasFeature;
    $request = Request::create('/test', 'GET');

    // The middleware should abort with 403
    try {
        $middleware->handle($request, fn () => new Response('success'), 'premium-zone');
        $this->fail('Expected abort exception was not thrown.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
        expect($e->getMessage())->toContain('Tenant context required');
    }
});
