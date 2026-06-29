<?php

use App\Jobs\IngestPaymentNotification;
use App\Models\Landlord;
use App\Models\PaymentNotification;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->withoutMix();

    $this->admin = Landlord::factory()->create([
        'email_verified_at' => now(),
    ]);
});

test('index loads with paginated notifications', function () {
    $this->actingAs($this->admin);

    PaymentNotification::factory()->count(3)->create();

    $response = $this->get(route('landlord.payment-notifications.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/payment-notifications/index')
            ->has('notifications.data', 3)
            ->has('filters')
            ->whereType('filters', 'array')
        );
});

test('index filters by parse_status', function () {
    $this->actingAs($this->admin);

    $parsed = PaymentNotification::factory()->create(['parse_status' => 'parsed']);
    $failed = PaymentNotification::factory()->failed()->create();

    $response = $this->get(route('landlord.payment-notifications.index', [
        'parse_status' => 'failed',
    ]));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/payment-notifications/index')
            ->has('notifications.data', 1)
            ->where('notifications.data.0.id', $failed->id)
            ->where('filters.parse_status', 'failed')
        );
});

test('index filters by bank_code', function () {
    $this->actingAs($this->admin);

    $bnc = PaymentNotification::factory()->create(['bank_code' => 'BNC']);
    $bdv = PaymentNotification::factory()->create(['bank_code' => 'BDV']);

    $response = $this->get(route('landlord.payment-notifications.index', [
        'bank_code' => 'BNC',
    ]));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/payment-notifications/index')
            ->has('notifications.data', 1)
            ->where('notifications.data.0.id', $bnc->id)
        );
});

test('index filters by reference in raw_text or parsed_data', function () {
    $this->actingAs($this->admin);

    $pending = PaymentNotification::factory()->pending()->create([
        'raw_text' => 'Pago recibido Ref: ABC-12345 por Bs 100',
    ]);
    $parsed = PaymentNotification::factory()->create([
        'raw_text' => 'Pago normal sin referencia especial',
        'parsed_data' => [
            'amount_cents' => 5000,
            'reference' => 'ABC-12345',
            'sender_phone_last4' => '5678',
        ],
    ]);
    PaymentNotification::factory()->create([
        'raw_text' => 'Otro pago sin relación',
        'parsed_data' => [
            'amount_cents' => 3000,
            'reference' => 'OTHER-999',
            'sender_phone_last4' => '1111',
        ],
        'parsed_at' => now(),
    ]);

    $response = $this->get(route('landlord.payment-notifications.index', [
        'reference' => 'ABC-12345',
    ]));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/payment-notifications/index')
            ->has('notifications.data', 2)
            ->where('notifications.data.0.id', $parsed->id)
            ->where('notifications.data.1.id', $pending->id)
            ->where('filters.reference', 'ABC-12345')
        );
});

test('index filters by date range', function () {
    $this->actingAs($this->admin);

    PaymentNotification::factory()->create([
        'created_at' => now()->subMonths(2),
    ]);
    $recent = PaymentNotification::factory()->create();

    $response = $this->get(route('landlord.payment-notifications.index', [
        'from' => now()->subMonth()->format('Y-m-d'),
        'to' => now()->addDay()->format('Y-m-d'),
    ]));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/payment-notifications/index')
            ->has('notifications.data', 1)
            ->where('notifications.data.0.id', $recent->id)
        );
});

test('index returns empty state when no notifications match', function () {
    $this->actingAs($this->admin);

    $response = $this->get(route('landlord.payment-notifications.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/payment-notifications/index')
            ->has('notifications.data', 0)
        );
});

test('reprocess failed notification dispatches job', function () {
    $this->actingAs($this->admin);

    Bus::fake();

    $notification = PaymentNotification::factory()->failed()->create();

    $response = $this->post(route('landlord.payment-notifications.reprocess', $notification));

    $response->assertRedirect();
    $response->assertSessionHas('success');

    Bus::assertDispatched(IngestPaymentNotification::class, function ($job) use ($notification) {
        return $job->notification->id === $notification->id;
    });
});

test('reprocess non-failed notification returns error', function () {
    $this->actingAs($this->admin);

    Bus::fake();

    $notification = PaymentNotification::factory()->create(['parse_status' => 'parsed']);

    $response = $this->post(route('landlord.payment-notifications.reprocess', $notification));

    $response->assertRedirect();
    $response->assertSessionHas('error');

    Bus::assertNotDispatched(IngestPaymentNotification::class);
});

test('reprocess non-existent notification returns 404', function () {
    $this->actingAs($this->admin);

    $response = $this->post(route('landlord.payment-notifications.reprocess', 99999));

    $response->assertNotFound();
});

test('non-admin user gets 403 on index', function () {
    $user = User::factory()->make();
    $this->actingAs($user);

    $response = $this->get(route('landlord.payment-notifications.index'));

    $response->assertForbidden();
});

test('non-admin user gets 403 on reprocess', function () {
    $user = User::factory()->make();
    $this->actingAs($user);

    $notification = PaymentNotification::factory()->failed()->create();

    $response = $this->post(route('landlord.payment-notifications.reprocess', $notification));

    $response->assertForbidden();
});

test('index has pagination links when more than 20 records', function () {
    $this->actingAs($this->admin);

    PaymentNotification::factory()->count(25)->create();

    $response = $this->get(route('landlord.payment-notifications.index'));

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('landlord/payment-notifications/index')
            ->has('notifications.data', 20)
            ->where('notifications.total', 25)
            ->where('notifications.last_page', 2)
            ->has('notifications.links')
        );
});
