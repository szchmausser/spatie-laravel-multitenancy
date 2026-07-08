<?php

use App\Enums\PaymentStatus;
use App\Models\Landlord;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMatch;
use App\Models\PaymentNotification;
use App\Models\Plan;
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

// ─── PENDING PAYMENTS (R1-R4) ─────────────────────────────────────────────

test('pending returns only unmatched pending payments', function () {
    $this->actingAs($this->admin);

    // Matched payment — should NOT appear in pending
    $matched = createPaymentForTest(['status' => PaymentStatus::Pending]);
    $notification = PaymentNotification::factory()->createQuietly();
    PaymentMatch::factory()->createQuietly([
        'payment_id' => $matched->id,
        'payment_notification_id' => $notification->id,
    ]);

    // Unmatched pending payment — SHOULD appear
    $unmatched = createPaymentForTest(['status' => PaymentStatus::Pending]);

    $response = $this->get(route('landlord.reconciliation.pending'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payments.data', 1)
            ->where('payments.data.0.id', $unmatched->id)
            ->has('filters')
            ->has('tenants')
            ->where('pollingInterval', fn ($val) => is_int($val))
        );
});

test('pending filters by search (tenant name)', function () {
    $this->actingAs($this->admin);

    $tenantAcme = Tenant::factory()->createQuietly(['name' => 'ACME Corp']);
    $tenantBeta = Tenant::factory()->createQuietly(['name' => 'Beta Inc']);

    createPaymentForTest(['tenant_id' => $tenantAcme->id, 'status' => PaymentStatus::Pending]);
    createPaymentForTest(['tenant_id' => $tenantBeta->id, 'status' => PaymentStatus::Pending]);

    $response = $this->get(route('landlord.reconciliation.pending', ['search' => 'ACME']));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payments.data', 1)
        );
});

test('pending filters by date range', function () {
    $this->actingAs($this->admin);

    $old = createPaymentForTest([
        'status' => PaymentStatus::Pending,
        'created_at' => now()->subDays(10),
    ]);
    $recent = createPaymentForTest([
        'status' => PaymentStatus::Pending,
        'created_at' => now()->subDay(),
    ]);

    $response = $this->get(route('landlord.reconciliation.pending', [
        'from' => now()->subDays(3)->format('Y-m-d'),
        'to' => now()->format('Y-m-d'),
    ]));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payments.data', 1)
            ->where('payments.data.0.id', $recent->id)
        );
});

test('pending empty state', function () {
    $this->actingAs($this->admin);

    $response = $this->get(route('landlord.reconciliation.pending'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payments.data', 0)
            ->has('unmatched_references', 0)
        );
});

test('pending returns unmatched bank notifications without linked payment', function () {
    $this->actingAs($this->admin);

    // Create an unmatched PaymentMatch (notification parsed, no payment linked)
    $notification = PaymentNotification::factory()->createQuietly();
    $unmatched = PaymentMatch::factory()->createQuietly([
        'payment_notification_id' => $notification->id,
        'payment_id' => null,
        'match_status' => 'unmatched',
    ]);

    // Create a matched PaymentMatch — should NOT appear in unmatched_references
    $payment = createPaymentForTest(['status' => PaymentStatus::Pending]);
    $matchedNotification = PaymentNotification::factory()->createQuietly();
    PaymentMatch::factory()->createQuietly([
        'payment_notification_id' => $matchedNotification->id,
        'payment_id' => $payment->id,
        'match_status' => 'matched',
    ]);

    $response = $this->get(route('landlord.reconciliation.pending'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('unmatched_references', 1)
            ->where('unmatched_references.0.id', $unmatched->id)
            ->where('unmatched_references.0.reference', $unmatched->parsed_reference)
            ->where('unmatched_references.0.amount_cents', $unmatched->parsed_amount_cents)
            ->where('unmatched_references.0.bank_code', $notification->bank_code)
        );
});

test('pending unmatched_references respects date filters', function () {
    $this->actingAs($this->admin);

    // Old notification (10 days ago) — should be filtered out
    $oldNotification = PaymentNotification::factory()->createQuietly();
    PaymentMatch::factory()->createQuietly([
        'payment_notification_id' => $oldNotification->id,
        'payment_id' => null,
        'match_status' => 'unmatched',
        'created_at' => now()->subDays(10),
    ]);

    // Recent notification (1 day ago) — should appear
    $recentNotification = PaymentNotification::factory()->createQuietly();
    $recentMatch = PaymentMatch::factory()->createQuietly([
        'payment_notification_id' => $recentNotification->id,
        'payment_id' => null,
        'match_status' => 'unmatched',
        'created_at' => now()->subDay(),
    ]);

    $response = $this->get(route('landlord.reconciliation.pending', [
        'from' => now()->subDays(3)->format('Y-m-d'),
        'to' => now()->format('Y-m-d'),
    ]));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('unmatched_references', 1)
            ->where('unmatched_references.0.id', $recentMatch->id)
        );
});

// ─── MATCHED PAYMENTS (R5-R7) ─────────────────────────────────────────────

test('matched returns only payments with match', function () {
    $this->actingAs($this->admin);

    $matched = createPaymentForTest(['status' => PaymentStatus::Verified]);
    $notification = PaymentNotification::factory()->createQuietly();
    PaymentMatch::factory()->createQuietly([
        'payment_id' => $matched->id,
        'payment_notification_id' => $notification->id,
        'match_status' => 'matched',
    ]);

    // Pending without match — should NOT appear
    createPaymentForTest(['status' => PaymentStatus::Pending]);

    $response = $this->get(route('landlord.reconciliation.matched'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payments.data', 1)
            ->where('payments.data.0.id', $matched->id)
            ->has('payments.data.0.match_type')
            ->has('filters')
            ->where('pollingInterval', fn ($val) => is_int($val))
        );
});

test('matched shows auto match type when verified_by is null', function () {
    $this->actingAs($this->admin);

    $payment = createPaymentForTest(['status' => PaymentStatus::Verified, 'verified_by' => null]);
    $notification = PaymentNotification::factory()->createQuietly();
    PaymentMatch::factory()->createQuietly([
        'payment_id' => $payment->id,
        'payment_notification_id' => $notification->id,
        'match_status' => 'matched',
    ]);

    $response = $this->get(route('landlord.reconciliation.matched'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payments.data.0.match_type', 'auto')
        );
});

test('matched shows manual match type when verified_by is set', function () {
    $this->actingAs($this->admin);

    $verifier = Landlord::factory()->createQuietly();
    $payment = createPaymentForTest([
        'status' => PaymentStatus::Verified,
        'verified_by' => $verifier->id,
        'verified_at' => now(),
    ]);
    $notification = PaymentNotification::factory()->createQuietly();
    PaymentMatch::factory()->createQuietly([
        'payment_id' => $payment->id,
        'payment_notification_id' => $notification->id,
        'match_status' => 'matched',
    ]);

    $response = $this->get(route('landlord.reconciliation.matched'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payments.data.0.match_type', 'manual')
        );
});

test('matched filters by match_status', function () {
    $this->actingAs($this->admin);

    $matched = createPaymentForTest();
    $n1 = PaymentNotification::factory()->createQuietly();
    PaymentMatch::factory()->createQuietly([
        'payment_id' => $matched->id,
        'payment_notification_id' => $n1->id,
        'match_status' => 'matched',
    ]);

    $unmatched = createPaymentForTest();
    $n2 = PaymentNotification::factory()->createQuietly();
    PaymentMatch::factory()->createQuietly([
        'payment_id' => $unmatched->id,
        'payment_notification_id' => $n2->id,
        'match_status' => 'unmatched',
    ]);

    $response = $this->get(route('landlord.reconciliation.matched', ['match_status' => 'unmatched']));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payments.data', 1)
            ->where('payments.data.0.id', $unmatched->id)
        );
});

test('matched empty state', function () {
    $this->actingAs($this->admin);

    $response = $this->get(route('landlord.reconciliation.matched'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payments.data', 0)
        );
});

// ─── PAYMENT DETAIL (R8-R9) ───────────────────────────────────────────────

test('show returns payment with full relations', function () {
    $this->actingAs($this->admin);

    $verifier = Landlord::factory()->createQuietly();
    $payment = createPaymentForTest([
        'status' => PaymentStatus::Verified,
        'verified_by' => $verifier->id,
        'verified_at' => now(),
    ]);
    $notification = PaymentNotification::factory()->createQuietly();
    PaymentMatch::factory()->createQuietly([
        'payment_id' => $payment->id,
        'payment_notification_id' => $notification->id,
        'match_status' => 'matched',
    ]);

    $response = $this->get(route('landlord.reconciliation.payments.show', $payment));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payment', fn (AssertableInertia $p) => $p
                ->where('id', $payment->id)
                ->has('tenant')
                ->has('order')
                ->has('payment_match')
                ->has('verifier')
                ->where('match_type', 'manual')
                ->etc()
            )
        );
});

// ─── STATISTICS (R10) ──────────────────────────────────────────────────────

test('stats returns aggregated payment data', function () {
    $this->actingAs($this->admin);

    createPaymentForTest([
        'status' => PaymentStatus::Pending,
        'amount_cents' => 1000,
        'created_at' => now()->subDay(),
    ]);
    createPaymentForTest([
        'status' => PaymentStatus::Verified,
        'amount_cents' => 2000,
        'created_at' => now()->subDay(),
    ]);

    $response = $this->get(route('landlord.reconciliation.stats', [
        'from' => now()->subDays(3)->format('Y-m-d'),
        'to' => now()->format('Y-m-d'),
    ]));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stats.total_payments', 2)
            ->where('stats.total_amount_cents', 3000)
            ->has('stats.by_status')
            ->has('stats.by_bank')
            ->has('tenants')
            ->where('pollingInterval', fn ($val) => is_int($val))
        );
});

test('stats filters by date range', function () {
    $this->actingAs($this->admin);

    createPaymentForTest([
        'status' => PaymentStatus::Pending,
        'created_at' => now()->subDays(10),
    ]);
    createPaymentForTest([
        'status' => PaymentStatus::Verified,
        'created_at' => now()->subDay(),
    ]);

    $response = $this->get(route('landlord.reconciliation.stats', [
        'from' => now()->subDays(2)->format('Y-m-d'),
        'to' => now()->format('Y-m-d'),
    ]));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stats.total_payments', 1)
        );
});

test('stats empty state', function () {
    $this->actingAs($this->admin);

    $response = $this->get(route('landlord.reconciliation.stats'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stats.total_payments', 0)
            ->where('stats.total_amount_cents', 0)
        );
});
