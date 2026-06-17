<?php

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ManualNotification;
use Database\Seeders\TenantPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

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

test('command fails without --tenants or --all', function () {
    $this->artisan('notification:send', ['message' => 'Hello'])
        ->assertExitCode(1);
});

test('command sends to specific tenants', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly(['name' => 'Acme Corp', 'database' => $this->testDatabase]);

    $owner = User::on('tenant')->createQuietly([
        'name' => 'Owner User',
        'email' => 'owner@acme.test',
        'password' => 'password',
    ]);
    $owner->assignRole('owner');

    $this->artisan('notification:send', [
        'message' => 'Hello Acme!',
        '--tenants' => (string) $tenant->id,
    ])->assertExitCode(0);

    Notification::assertSentTo($owner, ManualNotification::class);
});

test('command sends to all tenants with --all', function () {
    Notification::fake();

    $tenantA = Tenant::factory()->createQuietly(['name' => 'Tenant A', 'database' => $this->testDatabase]);
    $tenantB = Tenant::factory()->createQuietly(['name' => 'Tenant B', 'database' => 'tenant_b_notification_test']);

    $ownerA = User::on('tenant')->createQuietly([
        'name' => 'Owner A',
        'email' => 'owner-a@test.com',
        'password' => 'password',
    ]);
    $ownerA->assignRole('owner');

    $ownerB = User::on('tenant')->createQuietly([
        'name' => 'Owner B',
        'email' => 'owner-b@test.com',
        'password' => 'password',
    ]);
    $ownerB->assignRole('owner');

    $this->artisan('notification:send', [
        'message' => 'Hello everyone!',
        '--all' => true,
    ])->assertExitCode(0);

    Notification::assertSentTo($ownerA, ManualNotification::class);
    Notification::assertSentTo($ownerB, ManualNotification::class);
});

test('command filters by roles', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly(['name' => 'Acme Corp', 'database' => $this->testDatabase]);

    $owner = User::on('tenant')->createQuietly([
        'name' => 'Owner',
        'email' => 'owner@test.com',
        'password' => 'password',
    ]);
    $owner->assignRole('owner');

    $regularUser = User::on('tenant')->createQuietly([
        'name' => 'Regular',
        'email' => 'regular@test.com',
        'password' => 'password',
    ]);
    $regularUser->assignRole('member');

    $this->artisan('notification:send', [
        'message' => 'Hello owners!',
        '--tenants' => (string) $tenant->id,
        '--roles' => 'owner',
    ])->assertExitCode(0);

    Notification::assertSentTo($owner, ManualNotification::class);
    Notification::assertNothingSentTo($regularUser);
});

test('command dry-run does not send notifications', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly(['name' => 'Acme Corp', 'database' => $this->testDatabase]);

    $owner = User::on('tenant')->createQuietly([
        'name' => 'Owner',
        'email' => 'owner@test.com',
        'password' => 'password',
    ]);
    $owner->assignRole('owner');

    $this->artisan('notification:send', [
        'message' => 'Preview only',
        '--tenants' => (string) $tenant->id,
        '--dry-run' => true,
    ])->assertExitCode(0);

    Notification::assertNothingSent();
});

test('command handles tenant with no matching users', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly(['name' => 'Empty Tenant', 'database' => $this->testDatabase]);

    // No users created for this tenant

    $this->artisan('notification:send', [
        'message' => 'Hello?',
        '--tenants' => (string) $tenant->id,
    ])->assertExitCode(0);

    Notification::assertNothingSent();
});

test('command fails for non-existent tenant', function () {
    $this->artisan('notification:send', [
        'message' => 'Hello?',
        '--tenants' => '999999',
    ])->assertExitCode(1);
});

test('command includes title when provided', function () {
    Notification::fake();

    $tenant = Tenant::factory()->createQuietly(['name' => 'Acme Corp', 'database' => $this->testDatabase]);

    $owner = User::on('tenant')->createQuietly([
        'name' => 'Owner',
        'email' => 'owner@test.com',
        'password' => 'password',
    ]);
    $owner->assignRole('owner');

    $this->artisan('notification:send', [
        'message' => 'Important update',
        '--tenants' => (string) $tenant->id,
        '--title' => 'System Notice',
    ])->assertExitCode(0);

    Notification::assertSentTo($owner, ManualNotification::class);
});
