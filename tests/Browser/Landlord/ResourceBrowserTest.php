<?php

use App\Models\Landlord;
use App\Models\Resource;

/**
 * Browser tests for the resource admin CRUD flow.
 *
 * Verifies the landlord can:
 *  - browse the resources list with data
 *  - see the create form with all fields
 *  - load the edit form with existing resource data
 *  - trigger validation errors on empty submit
 *
 * File upload is NOT tested here because Pest browser tests
 * don't have a reliable way to attach files via Playwright.
 * Upload logic is covered by feature tests.
 */
beforeEach(function () {
    $this->admin = Landlord::factory()->createQuietly();
});

test('admin can see the resources list page', function () {
    $resource = Resource::factory()->create(['name' => 'Test Resource']);

    $this->actingAs($this->admin)
        ->visit(route('landlord.resources.index'))
        ->assertSee('Resources')
        ->assertSee('Test Resource')
        ->assertNoJavaScriptErrors();
});

test('admin can see the resource create form', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.resources.create'))
        ->assertSee('Publish Resource')
        ->assertSee('Name')
        ->assertSee('Slug')
        ->assertSee('Description')
        ->assertSee('Upload file')
        ->assertSee('Premium')
        ->assertNoJavaScriptErrors();
});

test('admin can see the resource edit form with existing data', function () {
    $resource = Resource::factory()->create([
        'name' => 'Editable Resource',
        'slug' => 'editable-resource',
        'description' => 'A resource to edit',
        'is_premium' => false,
        'price_cents' => 0,
    ]);

    $this->actingAs($this->admin)
        ->visit(route('landlord.resources.edit', $resource))
        ->assertSee('Edit Resource')
        ->assertValue('@input-name', 'Editable Resource')
        ->assertValue('@input-slug', 'editable-resource')
        ->assertNoJavaScriptErrors();
});

test('resource create form shows validation errors on empty submit', function () {
    $this->actingAs($this->admin)
        ->visit(route('landlord.resources.create'))
        ->click('@submit-resource-btn')
        ->waitForText('required')
        ->assertNoJavaScriptErrors();
});
