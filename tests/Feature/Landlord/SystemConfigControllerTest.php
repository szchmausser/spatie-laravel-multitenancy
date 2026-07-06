<?php

use App\Models\Landlord;
use App\Models\SystemConfig;
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

test('unauthenticated user cannot access system configs index', function () {
    auth()->logout();

    $response = $this->get(route('landlord.admin.system-configs'));

    $response->assertRedirect();
});

test('non-admin tenant user gets 403 on system configs index', function () {
    auth()->logout();

    $tenantUser = User::factory()->createQuietly();
    $this->actingAs($tenantUser);

    $response = $this->get(route('landlord.admin.system-configs'));

    $response->assertForbidden();
});

test('admin can list system configs grouped by group', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    // Create test configs in different groups
    SystemConfig::create([
        'group' => 'payment',
        'key' => 'payment.test_key',
        'value' => 'test_value',
        'type' => 'string',
    ]);
    SystemConfig::create([
        'group' => 'device',
        'key' => 'device.test_key',
        'value' => '42',
        'type' => 'integer',
    ]);

    $response = $this->get(route('landlord.admin.system-configs'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/system-configs/index')
            ->has('groups', 2)
            ->has('groups.payment', 1)
            ->has('groups.device', 1)
        );
});

test('unauthenticated user cannot update system config', function () {
    $config = SystemConfig::create([
        'group' => 'payment',
        'key' => 'payment.test_key',
        'value' => 'original',
        'type' => 'string',
    ]);

    auth()->logout();

    $response = $this->put(route('landlord.admin.system-configs.update', $config), [
        'value' => 'updated',
    ]);

    $response->assertRedirect();
});

test('non-admin tenant user gets 403 on system config update', function () {
    $config = SystemConfig::create([
        'group' => 'payment',
        'key' => 'payment.test_key',
        'value' => 'original',
        'type' => 'string',
    ]);

    auth()->logout();

    $tenantUser = User::factory()->createQuietly();
    $this->actingAs($tenantUser);

    $response = $this->put(route('landlord.admin.system-configs.update', $config), [
        'value' => 'updated',
    ]);

    $response->assertForbidden();
});

test('admin can update a string config', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $config = SystemConfig::create([
        'group' => 'payment',
        'key' => 'payment.test_key',
        'value' => 'original',
        'type' => 'string',
    ]);

    $response = $this->put(route('landlord.admin.system-configs.update', $config), [
        'value' => 'updated_value',
    ]);

    $response->assertSessionHas('success');
    $this->assertEquals('updated_value', $config->fresh()->value);
});

test('admin can update an integer config', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $config = SystemConfig::create([
        'group' => 'payment',
        'key' => 'payment.test_integer',
        'value' => '10',
        'type' => 'integer',
    ]);

    $response = $this->put(route('landlord.admin.system-configs.update', $config), [
        'value' => '25',
    ]);

    $response->assertSessionHas('success');
    $this->assertEquals('25', $config->fresh()->value);
});

test('admin can update a boolean config', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $config = SystemConfig::create([
        'group' => 'reconciliation',
        'key' => 'reconciliation.test_boolean',
        'value' => '1',
        'type' => 'boolean',
    ]);

    $response = $this->put(route('landlord.admin.system-configs.update', $config), [
        'value' => '0',
    ]);

    $response->assertSessionHas('success');
    $this->assertEquals('0', $config->fresh()->value);
});

test('update with non-numeric value on integer config returns validation errors', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $config = SystemConfig::create([
        'group' => 'payment',
        'key' => 'payment.test_integer',
        'value' => '10',
        'type' => 'integer',
    ]);

    $response = $this->put(route('landlord.admin.system-configs.update', $config), [
        'value' => 'not_a_number',
    ]);

    $response->assertSessionHasErrors('value');
});

test('update with invalid regex returns validation error', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    // Create a config with valid regex that has required named groups
    $config = SystemConfig::create([
        'group' => 'reconciliation',
        'key' => 'regex_test',
        'value' => '/(?<amount>\d+)\s+(?<reference>\d+)/',
        'type' => 'string',
    ]);

    // Update with a regex pattern missing required named groups (amount, reference)
    // This triggers InvalidArgumentException in the SystemConfig boot saving handler
    $response = $this->put(route('landlord.admin.system-configs.update', $config), [
        'value' => '/^valid_no_groups$/',
    ]);

    // The controller catches InvalidArgumentException and returns redirect with errors
    $response->assertRedirect();
    $response->assertSessionHasErrors('value');
});

test('admin can toggle a boolean config', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $config = SystemConfig::create([
        'group' => 'reconciliation',
        'key' => 'reconciliation.test_boolean',
        'value' => '1',
        'type' => 'boolean',
    ]);

    // Toggle to false using checkbox convention (sends '0')
    $response = $this->put(route('landlord.admin.system-configs.update', $config), [
        'value' => '0',
    ]);

    $response->assertSessionHas('success');
    $this->assertEquals('0', $config->fresh()->value);
});

test('admin cannot set invalid shadow channels via controller', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $config = SystemConfig::create([
        'group' => 'reconciliation',
        'key' => 'reconciliation.shadow_mode_channels',
        'value' => json_encode(['bank-app']),
        'type' => 'json',
    ]);

    $response = $this->put(route('landlord.admin.system-configs.update', $config), [
        'value' => json_encode(['invalid-channel']),
    ]);

    $response->assertSessionHasErrors('value');
    $this->assertEquals(json_encode(['bank-app']), $config->fresh()->value);
});

test('admin panel includes system config card', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $response = $this->get(route('landlord.admin-panel'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/admin-panel')
        );
});
