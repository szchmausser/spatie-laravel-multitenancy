<?php

use App\Models\Landlord;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('landlord admin panel can be accessed by authenticated landlord', function () {
    $admin = Landlord::factory()->create();
    $this->actingAs($admin);

    $response = $this->get(route('landlord.admin-panel'));
    $response->assertOk();
});
