<?php

use App\Enums\CancellationType;
use App\Events\PaymentCancelled;
use App\Listeners\NotifyPaymentRejected;
use App\Models\Landlord;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use App\Notifications\SystemAlert;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->tenant = Tenant::factory()->createQuietly();
    $this->order = Order::factory()->createQuietly(['tenant_id' => $this->tenant->id]);
    $this->payment = Payment::factory()->createQuietly([
        'order_id' => $this->order->id,
        'tenant_id' => $this->tenant->id,
    ]);
    $this->listener = app(NotifyPaymentRejected::class);
});

// ─── SystemDuplicate: SystemAlert sent to admins ───
// Tenant notification is tested separately in PaymentRejectedTest

it('sends SystemAlert for SystemDuplicate cancellation', function () {
    Notification::fake();
    $admin = Landlord::factory()->createQuietly();

    $event = new PaymentCancelled($this->payment, CancellationType::SystemDuplicate);
    $this->listener->handle($event);

    Notification::assertSentTo(
        $admin,
        SystemAlert::class,
        fn (SystemAlert $n): bool => $n->type === 'duplicate_reference',
    );
});

// ─── SystemExpired: no SystemAlert ───

it('does not send SystemAlert for SystemExpired cancellation', function () {
    Notification::fake();
    $admin = Landlord::factory()->createQuietly();

    $event = new PaymentCancelled($this->payment, CancellationType::SystemExpired);
    $this->listener->handle($event);

    Notification::assertNotSentTo($admin, SystemAlert::class);
});

// ─── Manual: no SystemAlert ───

it('does not send SystemAlert for Manual cancellation', function () {
    Notification::fake();
    $admin = Landlord::factory()->createQuietly();

    $event = new PaymentCancelled($this->payment, CancellationType::Manual);
    $this->listener->handle($event);

    Notification::assertNotSentTo($admin, SystemAlert::class);
});
