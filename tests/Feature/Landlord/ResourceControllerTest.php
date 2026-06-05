<?php

use App\Models\Landlord;
use App\Models\Resource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Tests for the Landlord\ResourceController (Phase 1.5C).
 *
 * Covers the full CRUD surface that the SaaS owner uses to
 * publish and retire downloadable resources: index, create, store,
 * edit, update (with and without a file replacement) and destroy
 * (which is a soft delete via is_active = false).
 *
 * All file uploads go to `Storage::fake('local')` so we never
 * touch the real disk. The tests assert on both the DB row and
 * the on-disk file (via `Storage::disk('local')::assertExists`)
 * because the controller writes the file in the same call that
 * inserts the row.
 */
beforeEach(function () {
    Storage::fake('local');
});

// ---------- access control ----------

test('guest is redirected to login from the resources index', function () {
    $this->get(route('landlord.resources.index'))
        ->assertRedirect(route('login'));
});

test('authenticated admin can access the resources index', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $this->get(route('landlord.resources.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('landlord/resources/index')
            ->has('resources', 0),
        );
});

// ---------- index ----------

test('index lists every resource, active first, then by name', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    Resource::factory()->create(['name' => 'Zeta', 'is_active' => true]);
    Resource::factory()->create(['name' => 'Alpha', 'is_active' => true]);
    Resource::factory()->inactive()->create(['name' => 'Mike', 'is_active' => false]);

    $this->get(route('landlord.resources.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('landlord/resources/index')
            ->has('resources', 3)
            ->where('resources.0.name', 'Alpha')
            ->where('resources.1.name', 'Zeta')
            ->where('resources.2.name', 'Mike'),
        );
});

// ---------- create / store ----------

test('admin can render the create form', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $this->get(route('landlord.resources.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('landlord/resources/create'),
        );
});

test('admin can publish a new resource with a file upload', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $payload = [
        'name' => 'Welcome Guide',
        'slug' => 'welcome-guide',
        'description' => 'A short onboarding document.',
        // UploadedFile::fake()->create uses kilobytes, so this is
        // exactly 240 * 1024 = 245760 bytes on disk.
        'file' => UploadedFile::fake()->create('guide.pdf', 240, 'application/pdf'),
        'is_premium' => false,
        'price_cents' => 0,
        'is_active' => true,
    ];

    $this->post(route('landlord.resources.store'), $payload)
        ->assertRedirect(route('landlord.resources.index'));

    $resource = Resource::query()->where('slug', 'welcome-guide')->first();

    expect($resource)->not->toBeNull();
    expect($resource->name)->toBe('Welcome Guide');
    expect($resource->description)->toBe('A short onboarding document.');
    expect($resource->is_premium)->toBeFalse();
    expect($resource->is_active)->toBeTrue();
    expect($resource->mime_type)->toBe('application/pdf');
    expect($resource->file_size_bytes)->toBe(245760);
    expect($resource->file_path)->toStartWith('resources/');
    expect($resource->file_path)->toEndWith('.pdf');

    Storage::disk('local')->assertExists($resource->file_path);
});

test('admin can publish a premium resource with a non-zero price', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $this->post(route('landlord.resources.store'), [
        'name' => 'Pro Playbook',
        'slug' => 'pro-playbook',
        'description' => null,
        'file' => UploadedFile::fake()->create('book.zip', 1024, 'application/zip'),
        'is_premium' => true,
        'price_cents' => 2999,
        'is_active' => true,
    ])->assertRedirect(route('landlord.resources.index'));

    $resource = Resource::query()->where('slug', 'pro-playbook')->first();

    expect($resource->is_premium)->toBeTrue();
    expect($resource->price_cents)->toBe(2999);
    expect($resource->mime_type)->toBe('application/zip');
});

test('store validates required fields', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $this->post(route('landlord.resources.store'), [])
        ->assertSessionHasErrors(['name', 'slug', 'file', 'is_premium', 'price_cents', 'is_active']);

    expect(Resource::query()->count())->toBe(0);
});

test('store rejects a duplicate slug', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    Resource::factory()->create(['slug' => 'taken']);

    $this->post(route('landlord.resources.store'), [
        'name' => 'Another',
        'slug' => 'taken',
        'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        'is_premium' => false,
        'price_cents' => 0,
        'is_active' => true,
    ])->assertSessionHasErrors(['slug']);
});

// ---------- edit / update ----------

test('admin can render the edit form with the resource data', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $resource = Resource::factory()->create([
        'name' => 'Sample',
        'slug' => 'sample',
        'price_cents' => 1500,
    ]);

    $this->get(route('landlord.resources.edit', $resource))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('landlord/resources/edit')
            ->where('resource.id', $resource->id)
            ->where('resource.name', 'Sample')
            ->where('resource.price_cents', 1500),
        );
});

test('admin can update metadata without replacing the file', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $resource = Resource::factory()->create([
        'name' => 'Old Name',
        'slug' => 'unchanged',
        'file_path' => 'resources/original-uuid.pdf',
        'file_size_bytes' => 500,
        'mime_type' => 'application/pdf',
    ]);

    // Put a real file at the original path so the update can
    // detect that it is NOT being replaced.
    Storage::disk('local')->put($resource->file_path, 'pdf-bytes');

    $this->put(route('landlord.resources.update', $resource), [
        'name' => 'New Name',
        'slug' => 'unchanged',
        'description' => 'Updated description',
        'is_premium' => true,
        'price_cents' => 999,
        'is_active' => true,
    ])->assertRedirect(route('landlord.resources.index'));

    $resource->refresh();

    expect($resource->name)->toBe('New Name');
    expect($resource->description)->toBe('Updated description');
    expect($resource->is_premium)->toBeTrue();
    expect($resource->price_cents)->toBe(999);
    // File metadata is preserved when no new file is uploaded.
    expect($resource->file_path)->toBe('resources/original-uuid.pdf');
    expect($resource->file_size_bytes)->toBe(500);
    expect($resource->mime_type)->toBe('application/pdf');

    Storage::disk('local')->assertExists('resources/original-uuid.pdf');
});

test('admin can replace the file on update', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $resource = Resource::factory()->create([
        'file_path' => 'resources/old-uuid.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::disk('local')->put('resources/old-uuid.pdf', 'old-bytes');

    $this->put(route('landlord.resources.update', $resource), [
        'name' => $resource->name,
        'slug' => $resource->slug,
        'description' => $resource->description,
        // 100 KB → 102400 bytes on disk.
        'file' => UploadedFile::fake()->create('new.pdf', 100, 'application/pdf'),
        'is_premium' => $resource->is_premium,
        'price_cents' => $resource->price_cents,
        'is_active' => $resource->is_active,
    ])->assertRedirect(route('landlord.resources.index'));

    $resource->refresh();

    expect($resource->file_path)->not->toBe('resources/old-uuid.pdf');
    expect($resource->file_path)->toStartWith('resources/');
    expect($resource->file_path)->toEndWith('.pdf');
    expect($resource->file_size_bytes)->toBe(102400);

    Storage::disk('local')->assertMissing('resources/old-uuid.pdf');
    Storage::disk('local')->assertExists($resource->file_path);
});

test('update allows the resource to keep its own slug', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $resource = Resource::factory()->create(['slug' => 'mine']);

    $this->put(route('landlord.resources.update', $resource), [
        'name' => 'Renamed',
        'slug' => 'mine',
        'is_premium' => false,
        'price_cents' => 0,
        'is_active' => true,
    ])->assertRedirect(route('landlord.resources.index'));

    expect(Resource::query()->where('slug', 'mine')->count())->toBe(1);
});

test('update rejects a slug already used by another resource', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    Resource::factory()->create(['slug' => 'taken']);
    $resource = Resource::factory()->create(['slug' => 'mine']);

    $this->put(route('landlord.resources.update', $resource), [
        'name' => 'Renamed',
        'slug' => 'taken',
        'is_premium' => false,
        'price_cents' => 0,
        'is_active' => true,
    ])->assertSessionHasErrors(['slug']);
});

// ---------- destroy (soft delete) ----------

test('admin can retire a resource by soft-deleting it', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $resource = Resource::factory()->create(['is_active' => true]);

    $this->delete(route('landlord.resources.destroy', $resource))
        ->assertRedirect(route('landlord.resources.index'));

    // Row stays (entitlements keep their FK target) but flips off.
    expect(Resource::query()->count())->toBe(1);
    expect($resource->fresh()->is_active)->toBeFalse();
});

test('retired resources are excluded from the public catalog', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $active = Resource::factory()->create(['name' => 'Live', 'is_active' => true]);
    Resource::factory()->inactive()->create(['name' => 'Retired', 'is_active' => false]);

    // The admin index still shows both (deactivated rows are
    // visible to the platform owner), but the public-facing
    // active() scope hides the retired one.
    expect(Resource::query()->active()->pluck('name')->all())->toBe(['Live']);
    expect(Resource::query()->count())->toBe(2);
});
