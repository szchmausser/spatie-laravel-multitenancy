<?php

use App\Models\Landlord;
use App\Models\User;
use Database\Seeders\TenantPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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

    $this->admin = Landlord::factory()->create();
});

test('unauthenticated user cannot access alerts index', function () {
    auth()->logout();

    $response = $this->get(route('landlord.alerts.index'));

    $response->assertRedirect();
});

test('non-admin tenant user gets 403 on alerts index', function () {
    auth()->logout();

    $tenantUser = User::factory()->createQuietly();
    $this->actingAs($tenantUser);

    $response = $this->get(route('landlord.alerts.index'));

    $response->assertForbidden();
});

test('index loads with paginated system alerts', function () {
    $this->actingAs($this->admin);

    // Create 3 system alerts
    for ($i = 0; $i < 3; $i++) {
        $this->admin->notifications()->create([
            'id' => Str::uuid(),
            'type' => 'App\Notifications\SystemAlert',
            'notifiable_type' => Landlord::class,
            'notifiable_id' => $this->admin->id,
            'data' => [
                'category' => 'system',
                'type' => 'test_alert',
                'message' => "System alert {$i}",
                'severity' => 'info',
            ],
        ]);
    }

    // Create a non-system notification that should NOT appear
    $this->admin->notifications()->create([
        'id' => Str::uuid(),
        'type' => 'App\Notifications\ManualNotification',
        'notifiable_type' => Landlord::class,
        'notifiable_id' => $this->admin->id,
        'data' => [
            'message' => 'Manual notification',
        ],
    ]);

    $response = $this->get(route('landlord.alerts.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/alerts')
            ->has('alerts.data', 3)
            ->has('filters')
        );
});

test('index filters by severity', function () {
    $this->actingAs($this->admin);

    // Create critical alert
    $this->admin->notifications()->create([
        'id' => Str::uuid(),
        'type' => 'App\Notifications\SystemAlert',
        'notifiable_type' => Landlord::class,
        'notifiable_id' => $this->admin->id,
        'data' => [
            'category' => 'system',
            'type' => 'heartbeat_offline',
            'message' => 'Server is down',
            'severity' => 'critical',
        ],
    ]);

    // Create warning alert
    $this->admin->notifications()->create([
        'id' => Str::uuid(),
        'type' => 'App\Notifications\SystemAlert',
        'notifiable_type' => Landlord::class,
        'notifiable_id' => $this->admin->id,
        'data' => [
            'category' => 'system',
            'type' => 'duplicate_reference',
            'message' => 'Possible duplicate',
            'severity' => 'warning',
        ],
    ]);

    $response = $this->get(route('landlord.alerts.index', ['severity' => 'critical']));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/alerts')
            ->has('alerts.data', 1)
            ->where('filters.severity', 'critical')
        );
});

test('index filters by read status', function () {
    $this->actingAs($this->admin);

    // Create unread alert
    $unreadId = Str::uuid();
    $this->admin->notifications()->create([
        'id' => $unreadId,
        'type' => 'App\Notifications\SystemAlert',
        'notifiable_type' => Landlord::class,
        'notifiable_id' => $this->admin->id,
        'data' => [
            'category' => 'system',
            'type' => 'test_alert',
            'message' => 'Unread alert',
            'severity' => 'info',
        ],
    ]);

    // Create read alert
    $readId = Str::uuid();
    $this->admin->notifications()->create([
        'id' => $readId,
        'type' => 'App\Notifications\SystemAlert',
        'notifiable_type' => Landlord::class,
        'notifiable_id' => $this->admin->id,
        'data' => [
            'category' => 'system',
            'type' => 'test_alert',
            'message' => 'Read alert',
            'severity' => 'info',
        ],
        'read_at' => now(),
    ]);

    // Filter unread
    $response = $this->get(route('landlord.alerts.index', ['read' => 'false']));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/alerts')
            ->has('alerts.data', 1)
            ->where('alerts.data.0.id', $unreadId->toString())
        );
});

test('index filters by date range', function () {
    $this->actingAs($this->admin);

    // Create alert from last month
    $this->admin->notifications()->create([
        'id' => Str::uuid(),
        'type' => 'App\Notifications\SystemAlert',
        'notifiable_type' => Landlord::class,
        'notifiable_id' => $this->admin->id,
        'data' => [
            'category' => 'system',
            'type' => 'old_alert',
            'message' => 'Old alert',
            'severity' => 'info',
        ],
        'created_at' => now()->subMonths(2),
    ]);

    // Create recent alert
    $recentId = Str::uuid();
    $this->admin->notifications()->create([
        'id' => $recentId,
        'type' => 'App\Notifications\SystemAlert',
        'notifiable_type' => Landlord::class,
        'notifiable_id' => $this->admin->id,
        'data' => [
            'category' => 'system',
            'type' => 'recent_alert',
            'message' => 'Recent alert',
            'severity' => 'info',
        ],
    ]);

    $response = $this->get(route('landlord.alerts.index', [
        'from' => now()->subMonth()->format('Y-m-d'),
        'to' => now()->addDay()->format('Y-m-d'),
    ]));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/alerts')
            ->has('alerts.data', 1)
            ->where('alerts.data.0.id', $recentId->toString())
        );
});

test('empty state when no system alerts exist', function () {
    $this->actingAs($this->admin);

    $response = $this->get(route('landlord.alerts.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/alerts')
            ->has('alerts.data', 0)
        );
});

test('invalid severity filter is silently ignored', function () {
    $this->actingAs($this->admin);

    $this->admin->notifications()->create([
        'id' => Str::uuid(),
        'type' => 'App\Notifications\SystemAlert',
        'notifiable_type' => Landlord::class,
        'notifiable_id' => $this->admin->id,
        'data' => [
            'category' => 'system',
            'type' => 'test_alert',
            'message' => 'Test alert',
            'severity' => 'info',
        ],
    ]);

    $response = $this->get(route('landlord.alerts.index', ['severity' => 'invalid']));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/alerts')
            ->has('alerts.data', 1)
        );
});

test('read action marks notification as read', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->actingAs($this->admin);

    $notification = $this->admin->notifications()->create([
        'id' => Str::uuid(),
        'type' => 'App\Notifications\SystemAlert',
        'notifiable_type' => Landlord::class,
        'notifiable_id' => $this->admin->id,
        'data' => [
            'category' => 'system',
            'type' => 'test_alert',
            'message' => 'Mark as read test',
            'severity' => 'warning',
        ],
    ]);

    $response = $this->post(route('landlord.alerts.read', ['notification' => $notification->id]));

    $response->assertOk();

    $this->assertNotNull($this->admin->notifications()->find($notification->id)?->read_at);
});

test('read action is idempotent when already read', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->actingAs($this->admin);

    $notification = $this->admin->notifications()->create([
        'id' => Str::uuid(),
        'type' => 'App\Notifications\SystemAlert',
        'notifiable_type' => Landlord::class,
        'notifiable_id' => $this->admin->id,
        'data' => [
            'category' => 'system',
            'type' => 'test_alert',
            'message' => 'Already read',
            'severity' => 'info',
        ],
        'read_at' => now(),
    ]);

    $readAt = $notification->read_at;

    $response = $this->post(route('landlord.alerts.read', ['notification' => $notification->id]));

    $response->assertOk();

    $fresh = $this->admin->notifications()->find($notification->id);
    expect($fresh->read_at->toISOString())->toBe($readAt->toISOString());
});

test('read action returns 404 for non-system notification', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->actingAs($this->admin);

    $notification = $this->admin->notifications()->create([
        'id' => Str::uuid(),
        'type' => 'App\Notifications\ManualNotification',
        'notifiable_type' => Landlord::class,
        'notifiable_id' => $this->admin->id,
        'data' => [
            'message' => 'Not a system alert',
        ],
    ]);

    $response = $this->post(route('landlord.alerts.read', ['notification' => $notification->id]));

    $response->assertNotFound();
});

test('read action returns 404 for non-owned notification', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $otherAdmin = Landlord::factory()->create();
    $this->actingAs($this->admin);

    $notification = $otherAdmin->notifications()->create([
        'id' => Str::uuid(),
        'type' => 'App\Notifications\SystemAlert',
        'notifiable_type' => Landlord::class,
        'notifiable_id' => $otherAdmin->id,
        'data' => [
            'category' => 'system',
            'type' => 'test_alert',
            'message' => 'Not owned',
            'severity' => 'warning',
        ],
    ]);

    $response = $this->post(route('landlord.alerts.read', ['notification' => $notification->id]));

    $response->assertNotFound();
});

test('read action returns 404 for non-existent notification', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->actingAs($this->admin);

    $response = $this->post(route('landlord.alerts.read', ['notification' => Str::uuid()->toString()]));

    $response->assertNotFound();
});
