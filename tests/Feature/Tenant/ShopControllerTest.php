<?php

use App\Models\Plan;
use App\Models\Resource;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Multitenancy\Http\Middleware\EnsureValidTenantSession;
use Spatie\Multitenancy\Http\Middleware\NeedsTenant;

beforeEach(function () {
    $testDatabase = config('database.connections.landlord.database');
    config(['database.connections.tenant.database' => $testDatabase]);
    DB::purge('tenant');

    $this->withoutMiddleware([
        VerifyCsrfToken::class,
        NeedsTenant::class,
        EnsureValidTenantSession::class,
    ]);

    $this->tenant = Tenant::factory()->createQuietly();
    $this->user = User::factory()->createQuietly();
    $this->tenant->makeCurrent();
    $this->actingAs($this->user);
});

test('authenticated tenant can access /shop', function () {
    Plan::factory()->createQuietly(['is_active' => true, 'price_cents' => 0, 'slug' => 'free']);

    $this->get(route('shop.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('shop/index')
            ->has('plans')
            ->has('resources')
        );
});

test('shop page returns current plan, plans, and resources', function () {
    Plan::factory()->createQuietly(['is_active' => true, 'price_cents' => 1000, 'slug' => 'basic']);
    Resource::factory()->createQuietly(['is_active' => true, 'is_premium' => false, 'slug' => 'free-doc']);
    Resource::factory()->createQuietly(['is_active' => true, 'is_premium' => true, 'slug' => 'premium-doc', 'price_cents' => 500]);

    $this->get(route('shop.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('shop/index')
            ->has('plans', 1)
            ->has('resources', 2)
            ->has('currentPlan')
        );
});

test('unauthenticated user is redirected to login', function () {
    $this->app['auth']->forgetGuards();

    $this->get('/shop')
        ->assertRedirect(route('login'));
});
