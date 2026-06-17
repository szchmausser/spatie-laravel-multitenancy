<?php

use App\Models\Landlord;
use App\Models\Tenant;

/**
 * Browser test for editing an existing tenant from the landlord admin panel.
 *
 * Covers:
 *   - Edit page loads with pre-filled form data
 *   - Editing a tenant updates the name
 */
beforeEach(function () {
    $this->admin = Landlord::factory()->createQuietly();
});

test('edit tenant page loads with pre-filled data', function () {
    $tenant = Tenant::factory()->createQuietly();

    $this->actingAs($this->admin)
        ->visit(route('landlord.tenants.edit', $tenant->id))
        ->waitForText('Edit Tenant')
        ->assertSee('Edit Tenant')
        ->assertSee('Name')
        ->assertSee('Domain')
        ->assertSee('Database')
        ->assertValue('[data-testid="edit-input-name"]', $tenant->name)
        ->assertVisible('[data-testid="edit-tenant-submit-btn"]')
        ->assertNoJavaScriptErrors();
});

test('edit tenant flow updates name', function () {
    $tenant = Tenant::factory()->createQuietly();
    $updatedName = 'Updated Tenant Name';

    $this->actingAs($this->admin)
        ->visit(route('landlord.tenants.edit', $tenant->id))
        ->waitForText('Edit Tenant')
        // Clear and type new name
        ->clear('[data-testid="edit-input-name"]')
        ->type('[data-testid="edit-input-name"]', $updatedName)
        ->click('[data-testid="edit-tenant-submit-btn"]')
        // After successful update, user is redirected to tenant index
        ->waitForText('Tenants')
        ->assertSee($updatedName)
        ->assertNoJavaScriptErrors();
});
