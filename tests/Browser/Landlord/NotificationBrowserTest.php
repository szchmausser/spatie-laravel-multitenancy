<?php

use App\Models\Landlord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->admin = Landlord::factory()->createQuietly();

    // Point the tenant connection to the same DB as landlord for testing
    $testDatabase = config('database.connections.landlord.database');
    config(['database.connections.tenant.database' => $testDatabase]);
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

    // Create a tenant with a user for testing
    $this->tenant = Tenant::factory()->createQuietly(['name' => 'Acme Corp', 'database' => $testDatabase]);

    $owner = User::on('tenant')->createQuietly(['name' => 'Owner User', 'email' => 'owner@acme.test', 'password' => 'password']);
    $owner->assignRole('owner');
});

test('admin can visit compose page and see tenants', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.notifications.create'))
        ->assertSee('Send Notification')
        ->assertSee('Acme Corp')
        ->assertNoJavaScriptErrors();
});

test('admin can fill form and preview notification', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->visit(route('landlord.notifications.create'))
        ->fill('notification-title', 'Test Title')
        ->fill('notification-message', 'Hello tenants!')
        ->check("tenant-{$this->tenant->id}")
        ->press('@preview-btn')
        ->assertSee('Preview Notification')
        ->assertSee('Acme Corp')
        ->assertSee('Recipient Counts')
        ->assertNoJavaScriptErrors();
});

test('admin can send notification from preview', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->visit(route('landlord.notifications.create'))
        ->fill('notification-title', 'Test Title')
        ->fill('notification-message', 'Hello tenants!')
        ->check("tenant-{$this->tenant->id}")
        ->press('@preview-btn')
        ->assertSee('Preview Notification')
        ->press('@send-btn')
        ->assertSee('Notification History')
        ->assertNoJavaScriptErrors();
});

test('history shows sent notification', function () {
    Notification::fake();

    // Send a notification first
    $this->actingAs($this->admin)
        ->visit(route('landlord.notifications.create'))
        ->fill('notification-title', 'Test Title')
        ->fill('notification-message', 'Hello tenants!')
        ->check("tenant-{$this->tenant->id}")
        ->press('@preview-btn')
        ->press('@send-btn')
        ->assertSee('Notification History');

    // Verify the entry is visible
    $this->assertSee('Test Title')
        ->assertSee('Hello tenants!')
        ->assertNoJavaScriptErrors();
});
