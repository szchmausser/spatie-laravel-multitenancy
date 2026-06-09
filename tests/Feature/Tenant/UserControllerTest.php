<?php

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
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
});

test('unauthenticated user is redirected to login on GET /users', function () {
    $this->get(route('users.index'))
        ->assertRedirect(route('login'));
});

test('unauthenticated user is redirected to login on GET /users/create', function () {
    $this->get(route('users.create'))
        ->assertRedirect(route('login'));
});

test('unauthenticated user is redirected to login on POST /users', function () {
    $this->post(route('users.store'), [])
        ->assertRedirect(route('login'));
});

test('unauthenticated user is redirected to login on GET /users/{user}', function () {
    $user = User::factory()->createQuietly();

    $this->get(route('users.show', $user))
        ->assertRedirect(route('login'));
});

test('unauthenticated user is redirected to login on GET /users/{user}/edit', function () {
    $user = User::factory()->createQuietly();

    $this->get(route('users.edit', $user))
        ->assertRedirect(route('login'));
});

test('unauthenticated user is redirected to login on PUT /users/{user}', function () {
    $user = User::factory()->createQuietly();

    $this->put(route('users.update', $user), [])
        ->assertRedirect(route('login'));
});

test('unauthenticated user is redirected to login on DELETE /users/{user}', function () {
    $user = User::factory()->createQuietly();

    $this->delete(route('users.destroy', $user))
        ->assertRedirect(route('login'));
});

// --- Index / List ---

test('authenticated user can view paginated user list', function () {
    $user = User::factory()->createQuietly(['name' => 'Alice']);

    $this->actingAs($user)
        ->get(route('users.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/index')
            ->has('users')
            ->has('users.data', 1)
            ->where('users.data.0.name', 'Alice')
        );
});

test('index search filters users by name', function () {
    $owner = User::factory()->createQuietly(['name' => 'Owner']);
    User::factory()->createQuietly(['name' => 'Alice Smith']);
    User::factory()->createQuietly(['name' => 'Bob Jones']);

    $this->actingAs($owner)
        ->get(route('users.index', ['search' => 'Alice']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.name', 'Alice Smith')
        );
});

test('index search filters users by email', function () {
    $owner = User::factory()->createQuietly(['name' => 'Owner']);
    User::factory()->createQuietly(['email' => 'alice@example.com']);
    User::factory()->createQuietly(['email' => 'bob@example.com']);

    $this->actingAs($owner)
        ->get(route('users.index', ['search' => 'alice']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.email', 'alice@example.com')
        );
});

test('index shows paginated results when more than 15 users exist', function () {
    $owner = User::factory()->createQuietly(['name' => 'Owner']);
    User::factory()->count(20)->createQuietly();

    $this->actingAs($owner)
        ->get(route('users.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 15)
            ->has('users.links')
        );
});

// --- Show ---

test('authenticated user can view user detail', function () {
    $owner = User::factory()->createQuietly(['name' => 'Owner']);
    $user = User::factory()->createQuietly(['name' => 'John Doe', 'email' => 'john@example.com']);

    $this->actingAs($owner)
        ->get(route('users.show', $user))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/show')
            ->where('user.name', 'John Doe')
            ->where('user.email', 'john@example.com')
        );
});

test('show returns 404 for non-existent user', function () {
    $owner = User::factory()->createQuietly(['name' => 'Owner']);

    $this->actingAs($owner)
        ->get(route('users.show', 99999))
        ->assertNotFound();
});

// --- Create ---

test('authenticated user can view create user form', function () {
    $owner = User::factory()->createQuietly(['name' => 'Owner']);

    $this->actingAs($owner)
        ->get(route('users.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/create')
        );
});

test('store creates a user with valid data', function () {
    $owner = User::factory()->createQuietly(['name' => 'Owner']);

    $this->actingAs($owner)
        ->post(route('users.store'), [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('users', [
        'name' => 'New User',
        'email' => 'newuser@example.com',
    ]);
});

test('store validates required fields', function () {
    $owner = User::factory()->createQuietly(['name' => 'Owner']);

    $this->actingAs($owner)
        ->post(route('users.store'), [])
        ->assertSessionHasErrors(['name', 'email', 'password']);
});

test('store rejects duplicate email', function () {
    $owner = User::factory()->createQuietly(['name' => 'Owner']);
    User::factory()->createQuietly(['email' => 'existing@example.com']);

    $this->actingAs($owner)
        ->post(route('users.store'), [
            'name' => 'Duplicate',
            'email' => 'existing@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
        ->assertSessionHasErrors(['email']);
});

test('store rejects short password', function () {
    $owner = User::factory()->createQuietly(['name' => 'Owner']);

    $this->actingAs($owner)
        ->post(route('users.store'), [
            'name' => 'Short Pass',
            'email' => 'short@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
        ->assertSessionHasErrors(['password']);
});

// --- Edit ---

test('authenticated user can view edit user form', function () {
    $owner = User::factory()->createQuietly(['name' => 'Owner']);
    $user = User::factory()->createQuietly(['name' => 'Edit Me']);

    $this->actingAs($owner)
        ->get(route('users.edit', $user))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/edit')
            ->where('user.name', 'Edit Me')
        );
});

test('update modifies user name and email', function () {
    $owner = User::factory()->createQuietly(['name' => 'Owner']);
    $user = User::factory()->createQuietly(['name' => 'Old Name', 'email' => 'old@example.com']);

    $this->actingAs($owner)
        ->put(route('users.update', $user), [
            'name' => 'New Name',
            'email' => 'new@example.com',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'New Name',
        'email' => 'new@example.com',
    ]);
});

test('update with blank password leaves password unchanged', function () {
    $owner = User::factory()->createQuietly(['name' => 'Owner']);
    $user = User::factory()->createQuietly(['name' => 'Keep Pass']);
    $originalPassword = $user->password;

    $this->actingAs($owner)
        ->put(route('users.update', $user), [
            'name' => 'Updated Name',
            'email' => $user->email,
        ])
        ->assertRedirect();

    $user->refresh();
    expect($user->password)->toBe($originalPassword);
});

test('update with new password changes it', function () {
    $owner = User::factory()->createQuietly(['name' => 'Owner']);
    $user = User::factory()->createQuietly(['name' => 'Change Pass']);
    $originalPassword = $user->password;

    $this->actingAs($owner)
        ->put(route('users.update', $user), [
            'name' => 'Change Pass',
            'email' => $user->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])
        ->assertRedirect();

    $user->refresh();
    expect($user->password)->not->toBe($originalPassword);
});

test('update rejects duplicate email from another user', function () {
    $owner = User::factory()->createQuietly(['name' => 'Owner']);
    $user = User::factory()->createQuietly(['email' => 'user@example.com']);
    User::factory()->createQuietly(['email' => 'taken@example.com']);

    $this->actingAs($owner)
        ->put(route('users.update', $user), [
            'name' => $user->name,
            'email' => 'taken@example.com',
        ])
        ->assertSessionHasErrors(['email']);
});

test('update allows keeping own email', function () {
    $owner = User::factory()->createQuietly(['name' => 'Owner']);
    $user = User::factory()->createQuietly(['email' => 'keep@example.com']);

    $this->actingAs($owner)
        ->put(route('users.update', $user), [
            'name' => 'Updated',
            'email' => 'keep@example.com',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'email' => 'keep@example.com',
    ]);
});

test('update returns 404 for non-existent user', function () {
    $owner = User::factory()->createQuietly(['name' => 'Owner']);

    $this->actingAs($owner)
        ->put(route('users.update', 99999), [
            'name' => 'Ghost',
            'email' => 'ghost@example.com',
        ])
        ->assertNotFound();
});

// --- Delete ---

test('authenticated user can delete another user', function () {
    $owner = User::factory()->createQuietly(['name' => 'Owner']);
    $user = User::factory()->createQuietly(['name' => 'Delete Me']);

    $this->actingAs($owner)
        ->delete(route('users.destroy', $user))
        ->assertRedirect();

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

test('user cannot delete themselves', function () {
    $owner = User::factory()->createQuietly(['name' => 'Owner']);

    $this->actingAs($owner)
        ->delete(route('users.destroy', $owner))
        ->assertSessionHasErrors('user');

    $this->assertDatabaseHas('users', ['id' => $owner->id]);
});

test('delete returns 404 for non-existent user', function () {
    $owner = User::factory()->createQuietly(['name' => 'Owner']);

    $this->actingAs($owner)
        ->delete(route('users.destroy', 99999))
        ->assertNotFound();
});

// --- Tenant isolation ---

test('users are scoped to the tenant connection', function () {
    // Verify that User queries go through the tenant connection,
    // not the landlord connection. In production, each tenant has
    // its own database, so cross-tenant access is impossible.
    $user = User::factory()->createQuietly(['name' => 'Tenant User']);

    // The user must exist on the tenant connection
    expect(User::on('tenant')->find($user->id))->not->toBeNull();

    // The User model's connection is 'tenant' (via UsesTenantConnection)
    expect((new User)->getConnectionName())->toBe('tenant');
});

// --- Full integration flow ---

test('full user management flow: create, verify, edit, verify, delete, verify', function () {
    $owner = User::factory()->createQuietly(['name' => 'Owner', 'email' => 'owner@example.com']);

    // Step 1: Create a new user
    $this->actingAs($owner)
        ->post(route('users.store'), [
            'name' => 'Integration User',
            'email' => 'integration@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
        ->assertRedirect();

    $createdUser = User::where('email', 'integration@example.com')->first();
    expect($createdUser)->not->toBeNull();
    expect($createdUser->name)->toBe('Integration User');

    // Step 2: Verify the user appears in the index
    $this->actingAs($owner)
        ->get(route('users.index', ['search' => 'integration']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.email', 'integration@example.com')
        );

    // Step 3: Edit the user — change name, keep password blank
    $originalPassword = $createdUser->password;

    $this->actingAs($owner)
        ->put(route('users.update', $createdUser), [
            'name' => 'Updated Integration User',
            'email' => 'integration@example.com',
        ])
        ->assertRedirect();

    $createdUser->refresh();
    expect($createdUser->name)->toBe('Updated Integration User');
    expect($createdUser->password)->toBe($originalPassword);

    // Step 4: Verify the updated name in show page
    $this->actingAs($owner)
        ->get(route('users.show', $createdUser))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('user.name', 'Updated Integration User')
        );

    // Step 5: Delete the user
    $this->actingAs($owner)
        ->delete(route('users.destroy', $createdUser))
        ->assertRedirect();

    // Step 6: Verify the user is gone from the index
    $this->actingAs($owner)
        ->get(route('users.index', ['search' => 'integration']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 0)
        );
});
