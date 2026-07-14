<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Landlord;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Resource;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\TenantPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['inertia.testing.ensure_pages_exist' => false]);

    $this->admin = Landlord::factory()->create();

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
    Schema::connection('tenant')->create('model_has_roles', function (Blueprint $table) {
        $table->foreignId('role_id')->constrained()->cascadeOnDelete();
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->primary(['role_id', 'model_id', 'model_type']);
        $table->index(['model_id', 'model_type']);
    });
    Schema::connection('tenant')->create('model_has_permissions', function (Blueprint $table) {
        $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->primary(['permission_id', 'model_id', 'model_type']);
        $table->index(['model_id', 'model_type']);
    });
    Schema::connection('tenant')->create('role_has_permissions', function (Blueprint $table) {
        $table->foreignId('role_id')->constrained()->cascadeOnDelete();
        $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
        $table->primary(['role_id', 'permission_id']);
    });

    (new TenantPermissionsSeeder)->runForCurrentConnection();
});

/**
 * Helper to create an Order with its dependency chain.
 */
function createOrderForTest(array $orderAttributes = [], array $paymentAttributes = []): Order
{
    $tenant = Tenant::factory()->createQuietly(['database' => 's_test_'.uniqid()]);
    $plan = Plan::factory()->createQuietly();

    $order = Order::factory()->createQuietly(array_merge([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => OrderStatus::Paid,
        'total_cents' => 5000,
    ], $orderAttributes));

    if ($paymentAttributes !== false) {
        Payment::factory()->createQuietly(array_merge([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'amount_cents' => $order->total_cents,
            'payment_method' => 'pago_movil',
            'status' => PaymentStatus::Verified,
        ], $paymentAttributes === [] ? [] : $paymentAttributes));
    }

    return $order;
}

// ─── AUTH GUARDS ──────────────────────────────────────────────────────────

test('unauthenticated user cannot access sales dashboard', function () {
    auth()->logout();

    $response = $this->get(route('landlord.sales.index'));

    $response->assertRedirect();
});

test('non-admin tenant user gets 403 on sales dashboard', function () {
    auth()->logout();

    $tenantUser = User::factory()->createQuietly();
    $this->actingAs($tenantUser);

    $response = $this->get(route('landlord.sales.index'));

    $response->assertForbidden();
});

// ─── KPI CARDS (R1) ───────────────────────────────────────────────────────

test('index loads with all Inertia props when empty (no data)', function () {
    $this->actingAs($this->admin);

    $response = $this->get(route('landlord.sales.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/sales/index')
            ->has('kpis', fn (AssertableInertia $kpis) => $kpis
                ->where('totalRevenue', 0)
                ->where('paidOrders', 0)
                ->where('averageOrderValue', 0)
                ->where('canceledAmount', 0)
                ->where('totalOrders', 0)
                ->has('changes')
            )
            ->has('revenueByMethod')
            ->has('revenueByType')
            ->has('topPlans')
            ->has('topResources')
            ->has('monthlyEvolution')
            ->has('recentOrders')
            ->has('revenueVsCancellations', fn (AssertableInertia $rvc) => $rvc
                ->where('revenue_cents', 0)
                ->where('canceled_cents', 0)
            )
            ->has('filters')
        );
});

test('index shows correct KPIs with data in range', function () {
    $this->actingAs($this->admin);

    createOrderForTest(
        ['total_cents' => 5000, 'status' => OrderStatus::Paid, 'created_at' => now()->subDay()],
        ['amount_cents' => 5000, 'status' => PaymentStatus::Verified, 'created_at' => now()->subDay()],
    );

    createOrderForTest(
        ['total_cents' => 3000, 'status' => OrderStatus::Paid, 'created_at' => now()->subDay()],
        ['amount_cents' => 3000, 'status' => PaymentStatus::Verified, 'created_at' => now()->subDay()],
    );

    createOrderForTest(
        ['total_cents' => 1000, 'status' => OrderStatus::Cancelled, 'created_at' => now()->subDay()],
        ['amount_cents' => 1000, 'status' => PaymentStatus::Cancelled, 'created_at' => now()->subDay()],
    );

    $response = $this->get(route('landlord.sales.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('kpis', fn (AssertableInertia $kpis) => $kpis
                ->where('totalRevenue', 8000)
                ->where('paidOrders', 2)
                ->where('averageOrderValue', 4000)
                ->where('canceledAmount', 1000)
                ->where('totalOrders', 3)
                ->etc()
            )
        );
});

test('index shows zero revenue when only cancellations exist', function () {
    $this->actingAs($this->admin);

    createOrderForTest(
        ['total_cents' => 2000, 'status' => OrderStatus::Cancelled, 'created_at' => now()->subDay()],
        ['amount_cents' => 2000, 'status' => PaymentStatus::Cancelled, 'created_at' => now()->subDay()],
    );

    $response = $this->get(route('landlord.sales.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('kpis', fn (AssertableInertia $kpis) => $kpis
                ->where('totalRevenue', 0)
                ->where('paidOrders', 0)
                ->where('averageOrderValue', 0)
                ->where('canceledAmount', 2000)
                ->etc()
            )
        );
});

// ─── DATE RANGE FILTERING (R6) ────────────────────────────────────────────

test('index filters by date range', function () {
    $this->actingAs($this->admin);

    // Old — outside range
    createOrderForTest(
        ['total_cents' => 5000, 'status' => OrderStatus::Paid, 'created_at' => now()->subDays(20)],
        ['amount_cents' => 5000, 'status' => PaymentStatus::Verified, 'created_at' => now()->subDays(20)],
    );

    // Recent — inside range
    createOrderForTest(
        ['total_cents' => 3000, 'status' => OrderStatus::Paid, 'created_at' => now()->subDay()],
        ['amount_cents' => 3000, 'status' => PaymentStatus::Verified, 'created_at' => now()->subDay()],
    );

    $response = $this->get(route('landlord.sales.index', [
        'from' => now()->subDays(5)->format('Y-m-d'),
        'to' => now()->format('Y-m-d'),
    ]));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('kpis', fn (AssertableInertia $kpis) => $kpis
                ->where('totalRevenue', 3000)
                ->where('paidOrders', 1)
                ->etc()
            )
        );
});

test('index returns all data when no date filter is provided', function () {
    $this->actingAs($this->admin);

    createOrderForTest(
        ['total_cents' => 1000, 'status' => OrderStatus::Paid, 'created_at' => now()->subYear()],
        ['amount_cents' => 1000, 'status' => PaymentStatus::Verified, 'created_at' => now()->subYear()],
    );

    $response = $this->get(route('landlord.sales.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('kpis.totalRevenue')
            ->where('kpis.totalRevenue', 1000)
        );
});

// ─── REVENUE BREAKDOWNS (R2) ──────────────────────────────────────────────

test('revenue by payment method groups correctly', function () {
    $this->actingAs($this->admin);

    $tenant = Tenant::factory()->createQuietly(['database' => config('database.connections.landlord.database')]);
    $plan = Plan::factory()->createQuietly();

    $order1 = Order::factory()->createQuietly(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'total_cents' => 3000]);
    Payment::factory()->createQuietly([
        'tenant_id' => $tenant->id, 'order_id' => $order1->id,
        'amount_cents' => 3000, 'payment_method' => 'pago_movil', 'status' => PaymentStatus::Verified,
    ]);

    $order2 = Order::factory()->createQuietly(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'total_cents' => 2000]);
    Payment::factory()->createQuietly([
        'tenant_id' => $tenant->id, 'order_id' => $order2->id,
        'amount_cents' => 2000, 'payment_method' => 'bank_transfer', 'status' => PaymentStatus::Verified,
    ]);

    $response = $this->get(route('landlord.sales.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('revenueByMethod', 2)
            ->where('revenueByMethod.0.amount_cents', fn ($val) => in_array($val, [3000, 2000]))
            ->where('revenueByMethod.1.amount_cents', fn ($val) => in_array($val, [3000, 2000]))
        );
});

test('revenue by type separates plans and resources', function () {
    $this->actingAs($this->admin);

    $tenant = Tenant::factory()->createQuietly(['database' => config('database.connections.landlord.database')]);
    $plan = Plan::factory()->createQuietly();

    // Plan order
    $order1 = Order::factory()->createQuietly(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'resource_id' => null, 'total_cents' => 5000]);
    Payment::factory()->createQuietly([
        'tenant_id' => $tenant->id, 'order_id' => $order1->id,
        'amount_cents' => 5000, 'payment_method' => 'pago_movil', 'status' => PaymentStatus::Verified,
    ]);

    // Resource order
    $resource = Resource::factory()->createQuietly();
    $order2 = Order::factory()->createQuietly(['tenant_id' => $tenant->id, 'plan_id' => null, 'resource_id' => $resource->id, 'total_cents' => 3000]);
    Payment::factory()->createQuietly([
        'tenant_id' => $tenant->id, 'order_id' => $order2->id,
        'amount_cents' => 3000, 'payment_method' => 'bank_transfer', 'status' => PaymentStatus::Verified,
    ]);

    $response = $this->get(route('landlord.sales.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('revenueByType', 2)
        );
});

// ─── TOP ITEMS (R3) ───────────────────────────────────────────────────────

test('top plans ranked by paid order count', function () {
    $this->actingAs($this->admin);

    $tenant = Tenant::factory()->createQuietly(['database' => config('database.connections.landlord.database')]);
    $planA = Plan::factory()->createQuietly(['name' => 'Plan A']);
    $planB = Plan::factory()->createQuietly(['name' => 'Plan B']);

    // Plan A: 2 orders
    for ($i = 0; $i < 2; $i++) {
        $order = Order::factory()->createQuietly(['tenant_id' => $tenant->id, 'plan_id' => $planA->id, 'total_cents' => 1000]);
        Payment::factory()->createQuietly([
            'tenant_id' => $tenant->id, 'order_id' => $order->id,
            'amount_cents' => 1000, 'payment_method' => 'pago_movil', 'status' => PaymentStatus::Verified,
        ]);
    }

    // Plan B: 1 order
    $order = Order::factory()->createQuietly(['tenant_id' => $tenant->id, 'plan_id' => $planB->id, 'total_cents' => 2000]);
    Payment::factory()->createQuietly([
        'tenant_id' => $tenant->id, 'order_id' => $order->id,
        'amount_cents' => 2000, 'payment_method' => 'pago_movil', 'status' => PaymentStatus::Verified,
    ]);

    $response = $this->get(route('landlord.sales.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('topPlans', 2)
            ->where('topPlans.0.order_count', 2)
            ->where('topPlans.0.plan.name', 'Plan A')
            ->where('topPlans.1.order_count', 1)
            ->where('topPlans.1.plan.name', 'Plan B')
        );
});

test('tied plans share same rank', function () {
    $this->actingAs($this->admin);

    $tenant = Tenant::factory()->createQuietly(['database' => config('database.connections.landlord.database')]);
    $planA = Plan::factory()->createQuietly();
    $planB = Plan::factory()->createQuietly();

    foreach ([$planA, $planB] as $plan) {
        $order = Order::factory()->createQuietly(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'total_cents' => 1000]);
        Payment::factory()->createQuietly([
            'tenant_id' => $tenant->id, 'order_id' => $order->id,
            'amount_cents' => 1000, 'payment_method' => 'pago_movil', 'status' => PaymentStatus::Verified,
        ]);
    }

    $response = $this->get(route('landlord.sales.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('topPlans', 2)
            ->where('topPlans.0.order_count', 1)
            ->where('topPlans.1.order_count', 1)
        );
});

// ─── MONTHLY EVOLUTION (R4) ──────────────────────────────────────────────

test('monthly evolution groups verified revenue by month', function () {
    $this->actingAs($this->admin);

    $tenant = Tenant::factory()->createQuietly(['database' => config('database.connections.landlord.database')]);
    $plan = Plan::factory()->createQuietly();

    // Payment in January 2026
    $order1 = Order::factory()->createQuietly(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'total_cents' => 5000]);
    Payment::factory()->createQuietly([
        'tenant_id' => $tenant->id, 'order_id' => $order1->id,
        'amount_cents' => 5000, 'payment_method' => 'pago_movil', 'status' => PaymentStatus::Verified,
        'created_at' => '2026-01-15',
    ]);

    // Payment in March 2026
    $order2 = Order::factory()->createQuietly(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'total_cents' => 3000]);
    Payment::factory()->createQuietly([
        'tenant_id' => $tenant->id, 'order_id' => $order2->id,
        'amount_cents' => 3000, 'payment_method' => 'pago_movil', 'status' => PaymentStatus::Verified,
        'created_at' => '2026-03-10',
    ]);

    $response = $this->get(route('landlord.sales.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('monthlyEvolution', 2)
            ->where('monthlyEvolution.0.month', '2026-01')
            ->where('monthlyEvolution.0.revenue_cents', 5000)
            ->where('monthlyEvolution.1.month', '2026-03')
            ->where('monthlyEvolution.1.revenue_cents', 3000)
        );
});

test('monthly evolution only shows months with data', function () {
    $this->actingAs($this->admin);

    $response = $this->get(route('landlord.sales.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('monthlyEvolution', 0)
        );
});

// ─── RECENT ORDERS (R5) ───────────────────────────────────────────────────

test('recent orders returns last 10 ordered by created_at desc', function () {
    $this->actingAs($this->admin);

    $tenant = Tenant::factory()->createQuietly(['database' => config('database.connections.landlord.database')]);
    $plan = Plan::factory()->createQuietly();

    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
        'total_cents' => 5000, 'status' => OrderStatus::Paid,
    ]);

    $response = $this->get(route('landlord.sales.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('recentOrders', 1)
            ->where('recentOrders.0.id', $order->id)
            ->where('recentOrders.0.total_cents', 5000)
            ->has('recentOrders.0.tenant')
            ->has('recentOrders.0.buyable')
            ->has('recentOrders.0.status')
            ->has('recentOrders.0.created_at')
        );
});

test('recent orders shows fewer than 10 when not enough exist', function () {
    $this->actingAs($this->admin);

    $response = $this->get(route('landlord.sales.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('recentOrders', 0)
        );
});

// ─── REVENUE VS CANCELLATIONS (R7) ────────────────────────────────────────

test('revenue vs cancellations shows both totals side by side', function () {
    $this->actingAs($this->admin);

    createOrderForTest(
        ['total_cents' => 8000, 'status' => OrderStatus::Paid],
        ['amount_cents' => 8000, 'status' => PaymentStatus::Verified],
    );

    createOrderForTest(
        ['total_cents' => 2000, 'status' => OrderStatus::Cancelled],
        ['amount_cents' => 2000, 'status' => PaymentStatus::Cancelled],
    );

    $response = $this->get(route('landlord.sales.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('revenueVsCancellations.revenue_cents', 8000)
            ->where('revenueVsCancellations.canceled_cents', 2000)
        );
});

test('revenue vs cancellations shows zeros when absent', function () {
    $this->actingAs($this->admin);

    $response = $this->get(route('landlord.sales.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('revenueVsCancellations.revenue_cents', 0)
            ->where('revenueVsCancellations.canceled_cents', 0)
        );
});

// ─── PERIOD COMPARISON ────────────────────────────────────────────────────

test('period change shows correct percentage when both periods have data', function () {
    $this->actingAs($this->admin);

    $tenant = Tenant::factory()->createQuietly(['database' => config('database.connections.landlord.database')]);
    $plan = Plan::factory()->createQuietly();

    // Current period: $6000
    $order1 = Order::factory()->createQuietly(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'total_cents' => 6000]);
    Payment::factory()->createQuietly([
        'tenant_id' => $tenant->id, 'order_id' => $order1->id,
        'amount_cents' => 6000, 'status' => PaymentStatus::Verified,
        'created_at' => now()->subDay(),
    ]);

    // Prior period: $4000 — placed 5 days ago, which falls in the prior window
    // (current window: now-3 to now; prior window: now-7 to now-4)
    $order2 = Order::factory()->createQuietly(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'total_cents' => 4000]);
    Payment::factory()->createQuietly([
        'tenant_id' => $tenant->id, 'order_id' => $order2->id,
        'amount_cents' => 4000, 'status' => PaymentStatus::Verified,
        'created_at' => now()->subDays(5),
    ]);

    $response = $this->get(route('landlord.sales.index', [
        'from' => now()->subDays(3)->format('Y-m-d'),
        'to' => now()->format('Y-m-d'),
    ]));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('kpis.changes')
            ->where('kpis.changes.totalRevenue', 50) // (6000-4000)/4000*100 = 50
        );
});

// ─── LARGE DATE RANGE STRESS TEST ─────────────────────────────────────────

// ─── TENANT PURCHASE HISTORY ─────────────────────────────────────────────

test('tenant show page shows orders when tenant has orders', function () {
    $this->actingAs($this->admin);

    $tenant = Tenant::factory()->createQuietly(['database' => 's_test_th_'.uniqid()]);
    $plan = Plan::factory()->createQuietly();

    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'total_cents' => 5000,
        'status' => OrderStatus::Paid,
    ]);

    $response = $this->get(route('landlord.tenants.show', $tenant));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/tenants/show')
            ->has('orders', 1)
            ->where('orders.0.id', $order->id)
            ->where('orders.0.total_cents', 5000)
            ->where('orders.0.status', 'paid')
            ->has('orders.0.buyable')
        );
});

test('tenant show page shows empty state when tenant has no orders', function () {
    $this->actingAs($this->admin);

    $tenant = Tenant::factory()->createQuietly(['database' => 's_test_th_empty_'.uniqid()]);

    $response = $this->get(route('landlord.tenants.show', $tenant));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/tenants/show')
            ->has('orders', 0)
        );
});

test('large date range with many orders returns without error', function () {
    $this->actingAs($this->admin);

    $tenant = Tenant::factory()->createQuietly(['database' => config('database.connections.landlord.database')]);
    $plan = Plan::factory()->createQuietly();

    for ($i = 0; $i < 100; $i++) {
        $order = Order::factory()->createQuietly([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
            'total_cents' => 1000, 'status' => OrderStatus::Paid,
            'created_at' => now()->subDays(rand(0, 365 * 5)),
        ]);
        Payment::factory()->createQuietly([
            'tenant_id' => $tenant->id, 'order_id' => $order->id,
            'amount_cents' => 1000, 'status' => PaymentStatus::Verified,
            'created_at' => $order->created_at,
        ]);
    }

    $response = $this->get(route('landlord.sales.index', [
        'from' => now()->subYears(5)->format('Y-m-d'),
        'to' => now()->format('Y-m-d'),
    ]));

    $response->assertOk();
});
