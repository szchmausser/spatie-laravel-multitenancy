<?php

use App\Enums\OrderStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\OrderExpired;
use Database\Seeders\TenantPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // Point tenant connection to landlord DB so makeCurrent() works in tests
    $testDatabase = config('database.connections.landlord.database');
    config(['database.connections.tenant.database' => $testDatabase]);
    DB::purge('tenant');

    // Create permission tables on the tenant connection
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

test('orders:expire sends OrderExpired notification to tenant admin users', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly([
        'database' => config('database.connections.landlord.database'),
    ]);

    // Ensure tenant has a subscription (required for tenant creation)
    $freePlan = Plan::factory()->createQuietly(['slug' => 'free']);
    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $freePlan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addMonth(),
    ]);

    // Create an admin user on the tenant connection
    $adminUser = User::on('tenant')->createQuietly([
        'name' => 'Tenant Admin',
        'email' => "admin-{$tenant->id}@test.com",
        'password' => 'password',
    ]);
    $adminUser->assignRole('owner');

    // Create an overdue pending order
    $plan = Plan::factory()->createQuietly();
    Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => OrderStatus::Pending,
        'expires_at' => now()->subDay(),
    ]);

    $this->artisan('orders:expire')
        ->assertExitCode(0);

    Notification::assertSentTo($adminUser, OrderExpired::class);
});

test('orders:expire does not send notification when no overdue orders exist', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly([
        'database' => config('database.connections.landlord.database'),
    ]);

    $freePlan = Plan::factory()->createQuietly(['slug' => 'free']);
    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $freePlan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addMonth(),
    ]);

    $adminUser = User::on('tenant')->createQuietly([
        'name' => 'Tenant Admin',
        'email' => "admin-{$tenant->id}@test.com",
        'password' => 'password',
    ]);
    $adminUser->assignRole('owner');

    // Create a non-overdue order (future expiry)
    $plan = Plan::factory()->createQuietly();
    Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => OrderStatus::Pending,
        'expires_at' => now()->addWeek(),
    ]);

    $this->artisan('orders:expire')
        ->assertExitCode(0);

    Notification::assertNothingSent();
});
