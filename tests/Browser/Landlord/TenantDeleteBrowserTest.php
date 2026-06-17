<?php

use App\Models\Landlord;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Browser test for deleting a tenant from the landlord admin panel.
 *
 * Covers:
 *   - Delete button appears on tenant detail page
 *   - Clicking delete triggers confirmation dialog
 *   - Confirming deletion removes tenant from list
 *
 * The destroy action attempts DROP DATABASE IF EXISTS which cannot run
 * inside a transaction. The browser test server handles requests
 * independently (no wrapping transaction), so DDL could execute.
 * However, to avoid dependency on PostgreSQL permissions, we mock
 * the unprepared statement.
 */
beforeEach(function () {
    $this->admin = Landlord::factory()->createQuietly();
});

test('tenant delete removes tenant from list', function () {
    $tenant = Tenant::factory()->createQuietly();

    // Mock the DROP DATABASE statement to avoid actual database operations
    DB::partialMock()->shouldReceive('statement')->andReturn(true);

    $this->actingAs($this->admin)
        ->visit(route('landlord.tenants.show', $tenant->id))
        ->waitForText($tenant->name)
        // Click the delete button on the detail page
        ->click('[data-testid="delete-tenant-trigger"]')
        ->assertVisible('[data-testid="confirm-delete-btn"]')
        // Confirm the deletion in the dialog
        ->click('[data-testid="confirm-delete-btn"]')
        // After deletion, redirected to index — verify tenant is gone
        ->waitForText('Tenants')
        ->assertDontSee($tenant->name)
        ->assertNoJavaScriptErrors();
});

test('tenant detail page shows delete button', function () {
    $tenant = Tenant::factory()->createQuietly();

    $this->actingAs($this->admin)
        ->visit(route('landlord.tenants.show', $tenant->id))
        ->waitForText($tenant->name)
        ->assertVisible('[data-testid="delete-tenant-trigger"]')
        ->assertSee('Delete')
        ->assertNoJavaScriptErrors();
});
