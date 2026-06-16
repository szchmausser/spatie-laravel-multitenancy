<?php

use App\Models\Landlord;
use App\Models\ManualNotificationLog;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ManualNotification;
use Database\Seeders\TenantPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->admin = Landlord::factory()->create();
    $this->actingAs($this->admin);

    // Point the tenant connection to the same DB as landlord for testing
    $this->testDatabase = config('database.connections.landlord.database');
    config(['database.connections.tenant.database' => $this->testDatabase]);
    DB::purge('tenant');

    // Ensure Spatie permission tables exist on the tenant connection
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

test('preview returns correct per-tenant counts', function () {
    $tenant = Tenant::factory()->createQuietly(['name' => 'Acme Corp', 'database' => $this->testDatabase]);

    $owner = User::on('tenant')->createQuietly(['name' => 'Owner User', 'email' => 'owner@acme.test', 'password' => 'password']);
    $owner->assignRole('owner');

    $admin = User::on('tenant')->createQuietly(['name' => 'Admin User', 'email' => 'admin@acme.test', 'password' => 'password']);
    $admin->assignRole('tenant-admin');

    $response = $this->postJson(route('landlord.notifications.preview'), [
        'message' => 'Hello tenants!',
        'tenant_ids' => [$tenant->id],
        'roles' => ['owner', 'tenant-admin'],
    ]);

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/notifications/compose')
            ->has('tenants')
            ->has('preview', 1)
            ->where('preview.0.tenant_name', 'Acme Corp')
            ->where('preview.0.recipient_count', 2)
            ->has('form')
        );
});

test('send dispatches notification and creates log', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly(['name' => 'Acme Corp', 'database' => $this->testDatabase]);

    $owner = User::on('tenant')->createQuietly(['name' => 'Owner User', 'email' => 'owner@acme.test', 'password' => 'password']);
    $owner->assignRole('owner');

    $response = $this->postJson(route('landlord.notifications.send'), [
        'title' => 'Test Title',
        'message' => 'Hello tenants!',
        'tenant_ids' => [$tenant->id],
        'roles' => ['owner'],
    ]);

    $response->assertRedirect(route('landlord.notifications.history'));

    $log = ManualNotificationLog::latest()->first();
    expect($log)->not->toBeNull();
    expect($log->title)->toBe('Test Title');
    expect($log->message)->toBe('Hello tenants!');
    expect($log->total_recipients)->toBe(1);
    expect($log->sent_by)->toBe($this->admin->id);

    Notification::assertSentTo($owner, ManualNotification::class);
});

test('send without tenants returns validation error', function () {
    $response = $this->post(route('landlord.notifications.send'), [
        'message' => 'Hello tenants!',
        'tenant_ids' => [],
    ]);

    $response->assertInvalid(['tenant_ids']);
});

test('history paginates at 20', function () {
    // Create 25 log records
    for ($i = 0; $i < 25; $i++) {
        ManualNotificationLog::create([
            'title' => "Notification {$i}",
            'message' => "Message {$i}",
            'tenant_ids' => [1, 2],
            'total_recipients' => 10,
            'sent_by' => $this->admin->id,
        ]);
    }

    $response = $this->get(route('landlord.notifications.history'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/notifications/history')
            ->has('logs.data', 20)
        );

    $response = $this->get(route('landlord.notifications.history', ['page' => 2]));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/notifications/history')
            ->has('logs.data', 5)
        );
});

test('connection restored after send', function () {
    $tenant = Tenant::factory()->createQuietly(['name' => 'Acme Corp', 'database' => $this->testDatabase]);

    $owner = User::on('tenant')->createQuietly(['name' => 'Owner User', 'email' => 'owner@acme.test', 'password' => 'password']);
    $owner->assignRole('owner');

    Notification::fake();

    $response = $this->postJson(route('landlord.notifications.send'), [
        'message' => 'Hello tenants!',
        'tenant_ids' => [$tenant->id],
        'roles' => ['owner'],
    ]);

    $response->assertRedirect();

    // Verify landlord connection still works after tenant iteration
    $logCount = ManualNotificationLog::count();
    expect($logCount)->toBe(1);
});

test('unauthenticated user gets 302 redirect to login', function () {
    auth()->logout();

    $response = $this->get(route('landlord.notifications.create'));

    $response->assertRedirect();
});

test('non-admin tenant user gets 403', function () {
    auth()->logout();

    $tenantUser = User::factory()->createQuietly();
    $this->actingAs($tenantUser);

    $response = $this->get(route('landlord.notifications.create'));

    $response->assertForbidden();
});

test('create page renders with tenants', function () {
    Tenant::factory()->createQuietly(['name' => 'Acme Corp', 'database' => $this->testDatabase]);
    Tenant::factory()->createQuietly(['name' => 'Beta Inc', 'database' => 'tenant_beta_test']);

    $response = $this->get(route('landlord.notifications.create'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/notifications/compose')
            ->has('tenants', 2)
        );
});
