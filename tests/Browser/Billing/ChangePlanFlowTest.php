<?php

use App\Models\Landlord;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser tests for the change-plan flow.
 *
 * Covers the full UI path for both consumer surfaces:
 *   - tenant-admin: user menu → /billing/change-plan →
 *     click "Change to {plan}" dialog → confirm POST →
 *     Inertia reload → page shows the new current plan.
 *   - landlord: tenant detail page → POST change → success flash.
 *
 * Browser tests do not get transactional isolation, so DDL issued
 * against the tenant connection (CREATE TABLE for the 5 Spatie
 * tables) commits immediately and persists across tests. The
 * setUp() override drops them so every test starts from a clean
 * schema — mirrors the pattern in UserMenuBadgeTest.
 */
beforeEach(function () {
    $testDatabase = config('database.connections.landlord.database');

    config(['database.connections.tenant.database' => $testDatabase]);
    DB::purge('tenant');

    $tableNames = config('permission.table_names');
    Schema::connection('tenant')->dropIfExists($tableNames['role_has_permissions']);
    Schema::connection('tenant')->dropIfExists($tableNames['model_has_roles']);
    Schema::connection('tenant')->dropIfExists($tableNames['model_has_permissions']);
    Schema::connection('tenant')->dropIfExists($tableNames['roles']);
    Schema::connection('tenant')->dropIfExists($tableNames['permissions']);

    DB::purge('tenant');
});

test('tenant-admin can change plan from the change-plan dialog', function () {
    $tenant = Tenant::factory()->createQuietly();

    pointTenantConnectionAtTestDatabase();
    $previousDefault = setDefaultConnectionToTenant();

    try {
        runSpatiePermissionMigration();
        runTenantPermissionsSeeder();

        $user = User::on('tenant')->create([
            'name' => 'Plan Changer',
            'email' => 'plan-changer@tenant.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        $user->assignRole('tenant-admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The tenant starts on the `basic` plan. The dialog
        // surfaces the other active plan (`premium`) for selection.
        $basic = Plan::factory()->create([
            'name' => 'Basic',
            'slug' => 'basic',
            'is_active' => true,
            'price_cents' => 0,
        ]);
        $premium = Plan::factory()->create([
            'name' => 'Premium',
            'slug' => 'premium',
            'is_active' => true,
            'price_cents' => 2900,
            'features' => ['premium-zone' => true],
        ]);

        $tenant->subscription()->create([
            'plan_id' => $basic->id,
            'status' => 'active',
            'ends_at' => now()->addMonth(),
        ]);

        $basicSlug = $basic->slug;
        $premiumSlug = $premium->slug;

        $this->actingAs($user)
            ->visit('/billing/change-plan')
            ->waitForText('Change plan')
            ->assertSeeIn(
                '@current-plan-card-'.$basicSlug,
                'Basic',
            )
            ->assertSeeIn(
                '@change-plan-card-'.$premiumSlug,
                'Premium',
            )
            ->click('@change-plan-trigger-btn-'.$premiumSlug)
            ->waitFor('@change-plan-dialog-'.$premiumSlug)
            ->within(
                '@change-plan-dialog-'.$premiumSlug,
                fn ($browser) => $browser->click(
                    '@change-plan-confirm-btn-'.$premiumSlug,
                ),
            )
            ->waitForText('Premium')
            ->assertSeeIn(
                '@current-plan-card-'.$premiumSlug,
                'Premium',
            )
            ->assertNoJavaScriptErrors();
    } finally {
        restoreDefaultConnection($previousDefault);
        DB::purge('tenant');
    }
});

test('landlord can change a tenant plan from the admin panel', function () {
    $landlord = Landlord::factory()->createQuietly();

    $basic = Plan::factory()->create([
        'name' => 'Basic',
        'slug' => 'basic',
        'is_active' => true,
        'price_cents' => 0,
    ]);
    $premium = Plan::factory()->create([
        'name' => 'Premium',
        'slug' => 'premium',
        'is_active' => true,
        'price_cents' => 2900,
        'features' => ['premium-zone' => true],
    ]);

    $tenant = Tenant::factory()->createQuietly();
    $tenant->subscription()->create([
        'plan_id' => $basic->id,
        'status' => 'active',
        'ends_at' => now()->addMonth(),
    ]);

    $this->actingAs($landlord)
        ->visit(route('landlord.tenants.show', $tenant))
        ->waitForText($tenant->domain)
        ->assertSee('Basic')
        ->select('@plan-select', (string) $premium->id)
        ->click('@assign-plan-btn')
        ->waitForText('Premium')
        ->assertSee('Premium')
        ->assertNoJavaScriptErrors();

    $tenant->refresh();
    expect($tenant->subscription->plan_id)->toBe($premium->id);
});
