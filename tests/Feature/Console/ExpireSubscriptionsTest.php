<?php

use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SubscriptionExpired;
use App\Notifications\SubscriptionExpiringWarning;
use Database\Seeders\TenantPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    // Point tenant connection to landlord DB so makeCurrent() works in tests
    $testDatabase = config('database.connections.landlord.database');
    config(['database.connections.tenant.database' => $testDatabase]);
    DB::purge('tenant');

    // Create permission tables on the tenant connection (needed for assignRole)
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

test('command expires past-due active subscriptions and resets plan to free', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly([
        'database' => config('database.connections.landlord.database'),
    ]);
    $plan = Plan::factory()->createQuietly();
    $freePlan = Plan::factory()->createQuietly(['slug' => 'free']);

    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->subDay(),
    ]);

    $this->artisan('subscriptions:expire')
        ->assertExitCode(0);

    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Expired);
    expect($subscription->plan_id)->toBe($freePlan->id);
});

test('command records system actor in history snapshot', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly([
        'database' => config('database.connections.landlord.database'),
    ]);
    $plan = Plan::factory()->createQuietly();
    $freePlan = Plan::factory()->createQuietly(['slug' => 'free']);

    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->subDay(),
    ]);

    $this->artisan('subscriptions:expire')
        ->assertExitCode(0);

    $history = SubscriptionHistory::where('tenant_id', $tenant->id)
        ->where('event_type', SubscriptionEventType::SubscriptionExpired)
        ->first();

    expect($history)->not->toBeNull();
    expect($history->actor_type->value)->toBe('system');
    expect($history->actor_name)->toBe('System');
    expect($history->actor_id)->toBeNull();
    expect($history->actor_email)->toBeNull();
    expect($history->old_plan_name)->toBe($plan->name);
    expect($history->old_status)->toBe(SubscriptionStatus::Active->value);
    expect($history->new_status)->toBe(SubscriptionStatus::Expired->value);
    expect($history->new_plan_name)->toBe($freePlan->name);
    expect($history->amount_cents)->toBe(0);
    expect($history->currency)->toBe('USD');
    expect($history->correlation_id)->not->toBeNull();
});

test('command skips already expired subscriptions', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly([
        'database' => config('database.connections.landlord.database'),
    ]);
    $plan = Plan::factory()->createQuietly();

    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Expired,
        'ends_at' => now()->subDay(),
    ]);

    $this->artisan('subscriptions:expire')
        ->assertExitCode(0);

    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Expired);
    Notification::assertNothingSent();
});

test('command skips active subscriptions with future ends_at', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly([
        'database' => config('database.connections.landlord.database'),
    ]);
    $plan = Plan::factory()->createQuietly();

    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addWeek(),
    ]);

    $this->artisan('subscriptions:expire')
        ->assertExitCode(0);

    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Active);
    Notification::assertNothingSent();
});

test('command dispatches warning notification within 3-day window', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly([
        'database' => config('database.connections.landlord.database'),
    ]);
    $plan = Plan::factory()->createQuietly();

    // Create an admin user so the command can send notifications
    $user = User::factory()->createQuietly();
    $user->assignRole('owner');

    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(2),
    ]);

    $this->artisan('subscriptions:expire')
        ->assertExitCode(0);

    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Active);

    Notification::assertSentTo($user, SubscriptionExpiringWarning::class);
});

test('command dispatches expired notification on status transition', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly([
        'database' => config('database.connections.landlord.database'),
    ]);
    $plan = Plan::factory()->createQuietly();
    $freePlan = Plan::factory()->createQuietly(['slug' => 'free']);

    $user = User::factory()->createQuietly();
    $user->assignRole('owner');

    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->subDay(),
    ]);

    $this->artisan('subscriptions:expire')
        ->assertExitCode(0);

    Notification::assertSentTo($user, SubscriptionExpired::class);
});

test('command does not reactivate expired subscriptions', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly([
        'database' => config('database.connections.landlord.database'),
    ]);
    $plan = Plan::factory()->createQuietly();

    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Expired,
        'ends_at' => now()->addMonth(),
    ]);

    $this->artisan('subscriptions:expire')
        ->assertExitCode(0);

    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Expired);
});

test('command is idempotent for warning notifications', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly([
        'database' => config('database.connections.landlord.database'),
    ]);
    $plan = Plan::factory()->createQuietly();

    $user = User::factory()->createQuietly();
    $user->assignRole('owner');

    $subscription = Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(2),
    ]);

    // First run — dispatches warning
    $this->artisan('subscriptions:expire')->assertExitCode(0);
    Notification::assertSentTo($user, SubscriptionExpiringWarning::class, 1);

    // Simulate a real notification record in the DB (since Notification::fake() doesn't persist)
    DB::connection('tenant')->table('notifications')->insert([
        'id' => Str::uuid()->toString(),
        'type' => SubscriptionExpiringWarning::class,
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Second run — should not duplicate warning (command checks notifications table)
    $this->artisan('subscriptions:expire')->assertExitCode(0);
    Notification::assertSentTo($user, SubscriptionExpiringWarning::class, 1);
});

test('command records subscription_expired history entry after expiry', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly([
        'database' => config('database.connections.landlord.database'),
    ]);
    $plan = Plan::factory()->createQuietly(['name' => 'Basic', 'price_cents' => 2900]);
    Plan::factory()->createQuietly(['slug' => 'free']);

    Subscription::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->subDay(),
    ]);

    $this->artisan('subscriptions:expire')
        ->assertExitCode(0);

    $history = SubscriptionHistory::where('tenant_id', $tenant->id)->first();
    expect($history)->not->toBeNull();
    expect($history->event_type)->toBe(SubscriptionEventType::SubscriptionExpired);
    expect($history->actor_id)->toBeNull();
    expect($history->ip_address)->toBeNull();
    expect($history->user_agent)->toBeNull();
    expect($history->old_plan_name)->toBe('Basic');
    expect($history->old_plan_price_cents)->toBe(2900);
    expect($history->old_status)->toBe(SubscriptionStatus::Active->value);
    expect($history->new_status)->toBe(SubscriptionStatus::Expired->value);
});
