<?php

use App\Enums\OrderStatus;
use App\Models\Landlord;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->admin = Landlord::factory()->create();
    $this->actingAs($this->admin);

    $this->testDatabase = config('database.connections.landlord.database');
    config(['database.connections.tenant.database' => $this->testDatabase]);
    DB::purge('tenant');

    Schema::connection('tenant')->dropIfExists('permissions');
    Schema::connection('tenant')->create('permissions', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });

    Schema::connection('tenant')->dropIfExists('roles');
    Schema::connection('tenant')->create('roles', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });

    Schema::connection('tenant')->dropIfExists('model_has_roles');
    Schema::connection('tenant')->create('model_has_roles', function (Blueprint $table) {
        $table->foreignId('role_id')->constrained()->cascadeOnDelete();
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->primary(['role_id', 'model_id', 'model_type']);
        $table->index(['model_id', 'model_type']);
    });

    Schema::connection('tenant')->dropIfExists('model_has_permissions');
    Schema::connection('tenant')->create('model_has_permissions', function (Blueprint $table) {
        $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->primary(['permission_id', 'model_id', 'model_type']);
        $table->index(['model_id', 'model_type']);
    });

    Schema::connection('tenant')->dropIfExists('role_has_permissions');
    Schema::connection('tenant')->create('role_has_permissions', function (Blueprint $table) {
        $table->foreignId('role_id')->constrained()->cascadeOnDelete();
        $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
        $table->primary(['role_id', 'permission_id']);
    });
});

test('unauthenticated user cannot access orders index', function () {
    auth()->logout();

    $response = $this->get(route('landlord.orders.index'));

    $response->assertRedirect();
});

test('non-admin tenant user gets 403 on orders index', function () {
    auth()->logout();

    $tenantUser = User::factory()->createQuietly();
    $this->actingAs($tenantUser);

    $response = $this->get(route('landlord.orders.index'));

    $response->assertForbidden();
});

test('admin can list orders', function () {
    $tenant = Tenant::factory()->createQuietly(['database' => $this->testDatabase]);
    $plan = Plan::factory()->createQuietly();

    Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => OrderStatus::Pending,
    ]);

    $response = $this->get(route('landlord.orders.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/orders/index')
            ->has('orders', 1)
        );
});

test('orders index filters by status', function () {
    $tenant = Tenant::factory()->createQuietly(['database' => $this->testDatabase]);
    $plan = Plan::factory()->createQuietly();

    Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => OrderStatus::Pending,
    ]);

    Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => OrderStatus::Paid,
    ]);

    $response = $this->get(route('landlord.orders.index', ['status' => 'pending']));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/orders/index')
            ->has('orders', 1)
        );
});

test('orders index filters by tenant_id', function () {
    $tenantA = Tenant::factory()->createQuietly(['name' => 'Tenant A', 'database' => $this->testDatabase]);
    $tenantB = Tenant::factory()->createQuietly(['name' => 'Tenant B', 'database' => 'tenant_b_order_test']);
    $plan = Plan::factory()->createQuietly();

    Order::factory()->createQuietly([
        'tenant_id' => $tenantA->id,
        'plan_id' => $plan->id,
    ]);

    Order::factory()->createQuietly([
        'tenant_id' => $tenantB->id,
        'plan_id' => $plan->id,
    ]);

    $response = $this->get(route('landlord.orders.index', ['tenant_id' => $tenantA->id]));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/orders/index')
            ->has('orders', 1)
        );
});

test('admin can view order detail', function () {
    $tenant = Tenant::factory()->createQuietly(['database' => $this->testDatabase]);
    $plan = Plan::factory()->createQuietly();

    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $response = $this->get(route('landlord.orders.show', $order));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/orders/show')
            ->has('order')
            ->where('order.id', $order->id)
        );
});

test('order detail returns 404 for non-existent order', function () {
    $response = $this->get(route('landlord.orders.show', 999999));

    $response->assertNotFound();
});
