<?php

use App\Models\Landlord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('unauthenticated user cannot upload avatar', function () {
    auth()->logout();

    $response = $this->postJson(route('profile.avatar.store'));

    $response->assertRedirect();
});

test('authenticated user can upload a valid avatar', function () {
    Storage::fake('public');

    $user = Landlord::factory()->createQuietly();
    $this->actingAs($user);

    $file = UploadedFile::fake()->image('avatar.jpg', 200, 200)->size(100);

    $response = $this->postJson(route('profile.avatar.store'), [
        'avatar' => $file,
    ]);

    $response->assertRedirect(route('profile.edit'));

    $user->refresh();
    expect($user->hasMedia('avatar'))->toBeTrue();
});

test('upload rejects non-image file', function () {
    Storage::fake('public');

    $user = Landlord::factory()->createQuietly();
    $this->actingAs($user);

    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

    $response = $this->postJson(route('profile.avatar.store'), [
        'avatar' => $file,
    ]);

    $response->assertSessionHasErrors('avatar');
});

test('upload rejects file exceeding max size', function () {
    Storage::fake('public');

    $user = Landlord::factory()->createQuietly();
    $this->actingAs($user);

    $file = UploadedFile::fake()->image('large.jpg', 4000, 4000)->size(3000);

    $response = $this->postJson(route('profile.avatar.store'), [
        'avatar' => $file,
    ]);

    $response->assertSessionHasErrors('avatar');
});

test('upload requires avatar field', function () {
    $user = Landlord::factory()->createQuietly();
    $this->actingAs($user);

    $response = $this->postJson(route('profile.avatar.store'));

    $response->assertSessionHasErrors('avatar');
});

test('authenticated user can remove avatar', function () {
    Storage::fake('public');

    $user = Landlord::factory()->createQuietly();
    $this->actingAs($user);

    $file = UploadedFile::fake()->image('avatar.jpg', 200, 200)->size(100);
    $user->addMedia($file)->toMediaCollection('avatar');

    $response = $this->deleteJson(route('profile.avatar.destroy'));

    $response->assertRedirect(route('profile.edit'));

    $user->refresh();
    expect($user->hasMedia('avatar'))->toBeFalse();
});

test('unauthenticated user cannot remove avatar', function () {
    auth()->logout();

    $response = $this->deleteJson(route('profile.avatar.destroy'));

    $response->assertRedirect();
});
