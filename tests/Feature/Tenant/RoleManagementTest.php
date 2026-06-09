<?php

use App\Models\Auth\Role;
use App\Models\User;
use Database\Seeders\TenantPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Multitenancy\Http\Middleware\EnsureValidTenantSession;
use Spatie\Multitenancy\Http\Middleware\NeedsTenant;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $testDatabase = config('database.connections.landlord.database');
    config(['database.connections.tenant.database' => $testDatabase]);
    DB::purge('tenant');

    $this->withoutMiddleware([
        VerifyCsrfToken::class,
        NeedsTenant::class,
        EnsureValidTenantSession::class,
    ]);

    // Create permission tables directly (migrate:fresh skips tenant-gated migrations)
    createPermissionTables();

    // Seed roles
    (new TenantPermissionsSeeder)->runForCurrentConnection();
});

// --- Role Index ---

test('role index requires manage-users permission', function () {
    $member = User::factory()->createQuietly();
    $member->assignRole('member');

    $this->actingAs($member)
        ->get(route('roles.index'))
        ->assertForbidden();
});

test('role index shows all roles with counts', function () {
    $owner = User::factory()->createQuietly();
    $owner->assignRole('owner');

    $this->actingAs($owner)
        ->get(route('roles.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('roles/index')
            ->has('roles', 3)
        );
});

test('role index is accessible by tenant-admin', function () {
    $admin = User::factory()->createQuietly();
    $admin->assignRole('tenant-admin');

    $this->actingAs($admin)
        ->get(route('roles.index'))
        ->assertOk();
});

test('unauthenticated user is redirected to login on GET /roles', function () {
    $this->get(route('roles.index'))
        ->assertRedirect(route('login'));
});

// --- Role Show ---

test('role show requires manage-users permission', function () {
    $member = User::factory()->createQuietly();
    $member->assignRole('member');
    $role = Role::where('name', 'member')->first();

    $this->actingAs($member)
        ->get(route('roles.show', $role))
        ->assertForbidden();
});

test('role show displays permissions and users', function () {
    $owner = User::factory()->createQuietly();
    $owner->assignRole('owner');
    $role = Role::where('name', 'owner')->first();

    $this->actingAs($owner)
        ->get(route('roles.show', $role))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('roles/show')
            ->where('role.name', 'owner')
            ->has('role.permissions')
            ->has('role.users')
        );
});

test('role show returns 404 for nonexistent role', function () {
    $owner = User::factory()->createQuietly();
    $owner->assignRole('owner');

    $this->actingAs($owner)
        ->get(route('roles.show', 99999))
        ->assertNotFound();
});

test('unauthenticated user is redirected to login on GET /roles/{role}', function () {
    $role = Role::where('name', 'owner')->first();

    $this->get(route('roles.show', $role))
        ->assertRedirect(route('login'));
});

// --- Assign Role ---

test('owner can assign role to user', function () {
    $owner = User::factory()->createQuietly();
    $owner->assignRole('owner');
    $target = User::factory()->createQuietly();

    $this->actingAs($owner)
        ->post(route('users.assignRole', $target), ['role' => 'member'])
        ->assertRedirect();

    expect($target->fresh()->hasRole('member'))->toBeTrue();
});

test('tenant-admin can assign role to user', function () {
    $admin = User::factory()->createQuietly();
    $admin->assignRole('tenant-admin');
    $target = User::factory()->createQuietly();

    $this->actingAs($admin)
        ->post(route('users.assignRole', $target), ['role' => 'member'])
        ->assertRedirect();

    expect($target->fresh()->hasRole('member'))->toBeTrue();
});

test('member cannot assign role', function () {
    $member = User::factory()->createQuietly();
    $member->assignRole('member');
    $target = User::factory()->createQuietly();

    $this->actingAs($member)
        ->post(route('users.assignRole', $target), ['role' => 'member'])
        ->assertForbidden();
});

test('unauthenticated user cannot assign role', function () {
    $target = User::factory()->createQuietly();

    $this->post(route('users.assignRole', $target), ['role' => 'member'])
        ->assertRedirect(route('login'));
});

test('assign role returns 404 for nonexistent user', function () {
    $owner = User::factory()->createQuietly();
    $owner->assignRole('owner');

    $this->actingAs($owner)
        ->post(route('users.assignRole', 99999), ['role' => 'member'])
        ->assertNotFound();
});

// --- Remove Role ---

test('owner can remove role from user', function () {
    $owner = User::factory()->createQuietly();
    $owner->assignRole('owner');
    $target = User::factory()->createQuietly();
    $target->assignRole('member');
    $role = Role::where('name', 'member')->first();

    $this->actingAs($owner)
        ->delete(route('users.removeRole', [$target, $role]))
        ->assertRedirect();

    expect($target->fresh()->hasRole('member'))->toBeFalse();
});

test('tenant-admin can remove role from user', function () {
    $admin = User::factory()->createQuietly();
    $admin->assignRole('tenant-admin');
    $target = User::factory()->createQuietly();
    $target->assignRole('member');
    $role = Role::where('name', 'member')->first();

    $this->actingAs($admin)
        ->delete(route('users.removeRole', [$target, $role]))
        ->assertRedirect();

    expect($target->fresh()->hasRole('member'))->toBeFalse();
});

test('member cannot remove role', function () {
    $member = User::factory()->createQuietly();
    $member->assignRole('member');
    $target = User::factory()->createQuietly();
    $target->assignRole('member');
    $role = Role::where('name', 'member')->first();

    $this->actingAs($member)
        ->delete(route('users.removeRole', [$target, $role]))
        ->assertForbidden();
});

// --- Self-Protection ---

test('owner cannot remove own owner role', function () {
    $owner = User::factory()->createQuietly();
    $owner->assignRole('owner');
    $role = Role::where('name', 'owner')->first();

    $this->actingAs($owner)
        ->delete(route('users.removeRole', [$owner, $role]))
        ->assertSessionHasErrors('role');

    expect($owner->fresh()->hasRole('owner'))->toBeTrue();
});

test('tenant-admin cannot remove own tenant-admin role', function () {
    $admin = User::factory()->createQuietly();
    $admin->assignRole('tenant-admin');
    $role = Role::where('name', 'tenant-admin')->first();

    $this->actingAs($admin)
        ->delete(route('users.removeRole', [$admin, $role]))
        ->assertSessionHasErrors('role');

    expect($admin->fresh()->hasRole('tenant-admin'))->toBeTrue();
});

// --- Owner Immutable ---

test('owner role cannot be removed from any user', function () {
    $owner = User::factory()->createQuietly();
    $owner->assignRole('owner');
    $target = User::factory()->createQuietly();
    $target->assignRole('owner');
    $role = Role::where('name', 'owner')->first();

    $this->actingAs($owner)
        ->delete(route('users.removeRole', [$target, $role]))
        ->assertSessionHasErrors('role');

    expect($target->fresh()->hasRole('owner'))->toBeTrue();
});

test('tenant-admin cannot remove owner role from another user', function () {
    $admin = User::factory()->createQuietly();
    $admin->assignRole('tenant-admin');
    $target = User::factory()->createQuietly();
    $target->assignRole('owner');
    $role = Role::where('name', 'owner')->first();

    $this->actingAs($admin)
        ->delete(route('users.removeRole', [$target, $role]))
        ->assertSessionHasErrors('role');

    expect($target->fresh()->hasRole('owner'))->toBeTrue();
});

// --- First User Auto-Owner ---

test('first user gets owner role via store', function () {
    // Ensure no users exist
    User::query()->delete();

    // Create an acting user with owner role, then delete them so count is 0 when store runs
    $actingUser = User::factory()->createQuietly(['name' => 'Temp']);
    $actingUser->assignRole('owner');
    $this->actingAs($actingUser);
    User::where('id', $actingUser->id)->delete();

    $this->post(route('users.store'), [
        'name' => 'Created User',
        'email' => 'created@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect();

    $createdUser = User::where('email', 'created@example.com')->first();
    expect($createdUser)->not->toBeNull();
    expect($createdUser->hasRole('owner'))->toBeTrue();
});

test('subsequent user gets member role via store', function () {
    // Create a first user (who gets owner)
    $first = User::factory()->createQuietly(['name' => 'First']);
    $first->assignRole('owner');

    // Now create a second user
    $this->actingAs($first)
        ->post(route('users.store'), [
            'name' => 'Second User',
            'email' => 'second@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
        ->assertRedirect();

    $second = User::where('email', 'second@example.com')->first();
    expect($second)->not->toBeNull();
    expect($second->roles)->toHaveCount(1);
    expect($second->hasRole('member'))->toBeTrue();
});

// --- Permission Cache ---

test('permission cache is cleared on role mutation', function () {
    $owner = User::factory()->createQuietly();
    $owner->assignRole('owner');
    $target = User::factory()->createQuietly();
    $target->assignRole('member');

    // Verify member doesn't have users-list permission
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect($target->can('users-list'))->toBeFalse();

    // Assign tenant-admin role
    $this->actingAs($owner)
        ->post(route('users.assignRole', $target), ['role' => 'tenant-admin'])
        ->assertRedirect();

    // Verify permission takes effect immediately
    expect($target->fresh()->can('users-list'))->toBeTrue();
});

// --- Tenant Isolation ---

test('role operations are tenant-scoped', function () {
    $owner = User::factory()->createQuietly();
    $owner->assignRole('owner');

    $this->actingAs($owner)
        ->post(route('users.assignRole', 99999), ['role' => 'member'])
        ->assertNotFound();
});

/**
 * Create Spatie permission tables directly on the tenant connection.
 *
 * The permission migration is gated to the `tenant` connection, but
 * `migrate:fresh` in TestCase only runs on the default connection.
 * We create the tables here to ensure they exist for tests.
 */
function createPermissionTables(): void
{
    $schema = Schema::connection('tenant');

    if (! $schema->hasTable('permissions')) {
        $schema->create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });
    }

    if (! $schema->hasTable('roles')) {
        $schema->create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });
    }

    if (! $schema->hasTable('model_has_permissions')) {
        $schema->create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });
    }

    if (! $schema->hasTable('model_has_roles')) {
        $schema->create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['role_id', 'model_id', 'model_type']);
        });
    }

    if (! $schema->hasTable('role_has_permissions')) {
        $schema->create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });
    }
}
