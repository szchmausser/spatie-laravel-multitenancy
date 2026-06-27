<?php

use App\Enums\PaymentStatus;
use App\Models\Landlord;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMatch;
use App\Models\PaymentNotification;
use App\Models\Plan;
use App\Models\SystemConfig;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\TenantPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    // The actual React page at resources/js/pages/landlord/reconciliation/index.tsx
    // will be created in the next slice (S8f-b). Disable Inertia's page-existence
    // check so tests can validate the controller data contract without the file.
    config(['inertia.testing.ensure_pages_exist' => false]);

    $this->testDatabase = config('database.connections.landlord.database');
    config(['database.connections.tenant.database' => $this->testDatabase]);
    DB::purge('tenant');

    Schema::connection('tenant')->create('permissions', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });
    Schema::connection('tenant')->create('roles', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });
    Schema::connection('tenant')->create('model_has_roles', function (Blueprint $table) {
        $table->foreignId('role_id')->constrained()->cascadeOnDelete();
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->primary(['role_id', 'model_id', 'model_type']);
        $table->index(['model_id', 'model_type']);
    });
    Schema::connection('tenant')->create('model_has_permissions', function (Blueprint $table) {
        $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->primary(['permission_id', 'model_id', 'model_type']);
        $table->index(['model_id', 'model_type']);
    });
    Schema::connection('tenant')->create('role_has_permissions', function (Blueprint $table) {
        $table->foreignId('role_id')->constrained()->cascadeOnDelete();
        $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
        $table->primary(['role_id', 'permission_id']);
    });

    (new TenantPermissionsSeeder)->runForCurrentConnection();

    $this->admin = Landlord::factory()->create();
});

/**
 * Create a Payment with its dependency chain without firing Tenant
 * model events (which would try to CREATE DATABASE inside a transaction).
 */
function createPaymentForTest(array $attributes = []): Payment
{
    $tenant = Tenant::factory()->createQuietly();
    $plan = Plan::factory()->createQuietly();
    $order = Order::factory()->createQuietly([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    return Payment::factory()->createQuietly(array_merge([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
    ], $attributes));
}

test('unauthenticated user cannot access reconciliation dashboard', function () {
    auth()->logout();

    $response = $this->get(route('landlord.reconciliation.index'));

    $response->assertRedirect();
});

test('non-admin tenant user gets 403 on reconciliation dashboard', function () {
    auth()->logout();

    $tenantUser = User::factory()->createQuietly();
    $this->actingAs($tenantUser);

    $response = $this->get(route('landlord.reconciliation.index'));

    $response->assertForbidden();
});

test('index loads with all KPI data when empty', function () {
    $this->actingAs($this->admin);

    $response = $this->get(route('landlord.reconciliation.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/reconciliation/index')
            ->has('matchRate', fn (AssertableInertia $rate) => $rate
                ->where('percentage', 0)
                ->where('total', 0)
                ->where('matched', 0)
                ->has('by_status')
            )
            ->where('autoverifiedToday', 0)
            ->where('activeAlerts', 0)
            ->where('failedNotifications', 0)
            ->where('shadowModeEnabled', false)
            ->where('orphanedPayments', [])
            ->where('orphanedNotifications', [])
            ->where('timeline', [])
        );
});

test('index returns match rate statistics', function () {
    $this->actingAs($this->admin);

    $payment1 = createPaymentForTest();
    $notification1 = PaymentNotification::factory()->createQuietly();
    PaymentMatch::factory()->createQuietly([
        'payment_id' => $payment1->id,
        'payment_notification_id' => $notification1->id,
        'match_status' => 'matched',
    ]);

    $payment2 = createPaymentForTest();
    $notification2 = PaymentNotification::factory()->createQuietly();
    PaymentMatch::factory()->createQuietly([
        'payment_id' => $payment2->id,
        'payment_notification_id' => $notification2->id,
        'match_status' => 'matched',
    ]);

    $payment3 = createPaymentForTest();
    $notification3 = PaymentNotification::factory()->createQuietly();
    PaymentMatch::factory()->createQuietly([
        'payment_id' => $payment3->id,
        'payment_notification_id' => $notification3->id,
        'match_status' => 'matched',
    ]);

    $payment4 = createPaymentForTest();
    $notification4 = PaymentNotification::factory()->createQuietly();
    PaymentMatch::factory()->createQuietly([
        'payment_id' => $payment4->id,
        'payment_notification_id' => $notification4->id,
        'match_status' => 'unmatched',
    ]);

    $payment5 = createPaymentForTest();
    $notification5 = PaymentNotification::factory()->createQuietly();
    PaymentMatch::factory()->createQuietly([
        'payment_id' => $payment5->id,
        'payment_notification_id' => $notification5->id,
        'match_status' => 'pending',
    ]);

    $response = $this->get(route('landlord.reconciliation.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('matchRate', fn (AssertableInertia $rate) => $rate
                ->where('total', 5)
                ->where('matched', 3)
                ->where('percentage', 60)
                ->has('by_status', fn (AssertableInertia $byStatus) => $byStatus
                    ->where('matched', 3)
                    ->where('unmatched', 1)
                    ->where('pending', 1)
                    ->where('duplicate', 0)
                )
            )
        );
});

test('shadow toggle rejects non-boolean payload with 422', function () {
    $this->actingAs($this->admin);

    $response = $this->patch(route('landlord.reconciliation.shadow-mode'), [
        'enabled' => 'not-a-boolean',
    ]);

    $response->assertSessionHasErrors('enabled');
});

test('toggle shadow mode enables and disables', function () {
    $this->actingAs($this->admin);

    // Enable shadow mode
    $response = $this->patch(route('landlord.reconciliation.shadow-mode'), [
        'enabled' => true,
    ]);

    $response->assertRedirect();
    $this->assertTrue(SystemConfig::get('reconciliation.shadow_mode_enabled'));

    // Verify the dashboard reflects the new value
    $this->get(route('landlord.reconciliation.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('shadowModeEnabled', true)
        );

    // Disable shadow mode
    $response = $this->patch(route('landlord.reconciliation.shadow-mode'), [
        'enabled' => false,
    ]);

    $response->assertRedirect();
    $this->assertFalse(SystemConfig::get('reconciliation.shadow_mode_enabled'));

    // Verify the dashboard reflects the change
    $this->get(route('landlord.reconciliation.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('shadowModeEnabled', false)
        );
});

test('index returns orphaned payments and timeline events', function () {
    $this->actingAs($this->admin);

    // Create an orphaned payment: pending, old (beyond 30 min threshold), without match
    $orphanedPayment = createPaymentForTest([
        'status' => PaymentStatus::Pending,
        'created_at' => now()->subHours(2),
        'amount_cents' => 2500,
    ]);

    // Create a recent payment that should NOT be orphaned (within threshold)
    createPaymentForTest([
        'status' => PaymentStatus::Pending,
        'created_at' => now()->subMinutes(5),
    ]);

    // Create a verified payment (appears in timeline as verification event)
    $verifiedPayment = createPaymentForTest([
        'verified_at' => now()->subHour(),
        'verified_by' => $this->admin->id,
    ]);
    $verifiedPayment->update(['status' => PaymentStatus::Verified]);

    // Create a match (appears in timeline)
    $matchPayment = createPaymentForTest();
    $matchNotification = PaymentNotification::factory()->createQuietly();
    PaymentMatch::factory()->createQuietly([
        'payment_id' => $matchPayment->id,
        'payment_notification_id' => $matchNotification->id,
        'match_status' => 'matched',
        'parsed_reference' => 'REF-TIMELINE',
        'created_at' => now()->subMinutes(30),
    ]);

    // Create a parsed notification (appears in timeline)
    $timelineNotification = PaymentNotification::factory()->createQuietly([
        'parsed_data' => [
            'amount_cents' => 3000,
            'reference' => 'NOTIF-REF',
            'sender_phone_last4' => '1234',
        ],
        'created_at' => now()->subMinutes(15),
    ]);

    $response = $this->get(route('landlord.reconciliation.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('orphanedPayments', 1)
            ->where('orphanedPayments.0.id', $orphanedPayment->id)
            ->where('orphanedPayments.0.amount_cents', 2500)
            // Timeline: 2 notifications + 1 verification + 1 match = 4 items.
            // Exact order depends on second-precision timestamps — verify count
            // and structure only.
            ->has('timeline', 4)
        );
});
