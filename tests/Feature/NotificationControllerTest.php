<?php

use App\Models\User;
use Database\Seeders\TenantPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Multitenancy\Http\Middleware\EnsureValidTenantSession;
use Spatie\Multitenancy\Http\Middleware\NeedsTenant;

beforeEach(function () {
    // Point the tenant connection to the same DB as landlord for testing
    $testDatabase = config('database.connections.landlord.database');
    config(['database.connections.tenant.database' => $testDatabase]);
    DB::purge('tenant');

    // Disable CSRF and tenant middlewares for HTTP tests
    $this->withoutMiddleware([
        VerifyCsrfToken::class,
        NeedsTenant::class,
        EnsureValidTenantSession::class,
    ]);

    // Ensure Spatie permission tables exist on the tenant connection.
    // dropIfExists handles the case where migrate:fresh already created
    // them via the tenant connection fallback to the default DB.
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

    // Seed roles
    (new TenantPermissionsSeeder)->runForCurrentConnection();
});

test('marks a notification as read', function () {
    $user = User::factory()->createQuietly(['name' => 'Test User']);
    $user->assignRole('owner');

    // Create a notification via the user's relationship (uses tenant connection)
    $notification = $user->notifications()->create([
        'id' => Str::uuid(),
        'type' => 'App\\Notifications\\SubscriptionExpiringWarning',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => json_encode(['message' => 'Test notification']),
    ]);

    $this->actingAs($user);

    $response = $this->put("/notifications/{$notification->id}");

    $response->assertRedirect();

    expect($user->fresh()->notifications()->whereNull('read_at')->count())->toBe(0);
});

test('marks all notifications as read', function () {
    $user = User::factory()->createQuietly(['name' => 'Test User']);
    $user->assignRole('owner');

    // Create multiple unread notifications via the user's relationship
    for ($i = 0; $i < 3; $i++) {
        $user->notifications()->create([
            'id' => Str::uuid(),
            'type' => 'App\\Notifications\\SubscriptionExpiringWarning',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['message' => "Notification {$i}"]),
        ]);
    }

    $this->actingAs($user);

    $response = $this->put('/notifications/read-all');

    $response->assertOk();

    expect($user->unreadNotifications()->count())->toBe(0);
});

test('returns 403 when marking notification of another user', function () {
    $user1 = User::factory()->createQuietly(['name' => 'User 1']);
    $user2 = User::factory()->createQuietly(['name' => 'User 2']);
    $user1->assignRole('owner');

    $notification = $user2->notifications()->create([
        'id' => Str::uuid(),
        'type' => 'App\\Notifications\\SubscriptionExpiringWarning',
        'notifiable_type' => User::class,
        'notifiable_id' => $user2->id,
        'data' => json_encode(['message' => 'Test notification']),
    ]);

    $this->actingAs($user1);

    $response = $this->put("/notifications/{$notification->id}");

    $response->assertNotFound();
});
