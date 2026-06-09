<?php

use App\Models\Tenant;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen is hidden on landlord domain', function () {
    // No makeCurrent() — landlord context.
    $response = $this->get(route('register'));

    $response->assertNotFound();
});

test('registration screen can be rendered on tenant domain', function () {
    $tenant = Tenant::factory()->createQuietly();
    $tenant->makeCurrent();

    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users cannot register on landlord domain', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    // Landlord registration is blocked — only tenant registration is allowed.
    // Landlord users are created via seeder or the admin panel.
    $response->assertForbidden();
});
