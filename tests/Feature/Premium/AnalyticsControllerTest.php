<?php

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\TenantPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->testDatabase = config('database.connections.landlord.database');
    config(['database.connections.tenant.database' => $this->testDatabase]);
    DB::purge('tenant');

    Schema::connection('tenant')->create('permissions', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });

    Schema::connection('tenant')->create('roles', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });

    Schema::connection('tenant')->create('model_has_permissions', function (Blueprint $table) {
        $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->primary(['permission_id', 'model_id', 'model_type']);
        $table->index(['model_id', 'model_type']);
    });

    Schema::connection('tenant')->create('model_has_roles', function (Blueprint $table) {
        $table->foreignId('role_id')->constrained()->cascadeOnDelete();
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->primary(['role_id', 'model_id', 'model_type']);
        $table->index(['model_id', 'model_type']);
    });

    Schema::connection('tenant')->create('role_has_permissions', function (Blueprint $table) {
        $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
        $table->foreignId('role_id')->constrained()->cascadeOnDelete();
        $table->primary(['permission_id', 'role_id']);
    });

    (new TenantPermissionsSeeder)->runForCurrentConnection();
});

test('unauthenticated user cannot access analytics', function () {
    auth()->logout();

    $response = $this->get(route('premium.analytics'));

    $response->assertRedirect();
});

test('tenant without premium-zone feature gets 403', function () {
    $tenant = Tenant::factory()->createQuietly(['database' => $this->testDatabase]);

    $plan = Plan::factory()->createQuietly([
        'features' => ['premium-zone' => false],
    ]);

    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $tenant->makeCurrent();

    $user = User::on('tenant')->createQuietly([
        'name' => 'Test User',
        'email' => 'test@test.com',
        'password' => 'password',
    ]);
    $user->assignRole('owner');

    $this->actingAs($user);

    $response = $this->get(route('premium.analytics'));

    $response->assertForbidden();
});

test('tenant with premium-zone feature can access analytics', function () {
    $tenant = Tenant::factory()->createQuietly(['database' => $this->testDatabase]);

    $plan = Plan::factory()->createQuietly([
        'features' => ['premium-zone' => true],
    ]);

    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $tenant->makeCurrent();

    $user = User::on('tenant')->createQuietly([
        'name' => 'Test User',
        'email' => 'test@test.com',
        'password' => 'password',
    ]);
    $user->assignRole('owner');

    $this->actingAs($user);

    $response = $this->get(route('premium.analytics'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('premium/analytics/index')
            ->has('stats')
            ->where('stats.total_users', 0)
            ->where('stats.active_sessions', 0)
            ->where('stats.revenue', 0)
        );
});
