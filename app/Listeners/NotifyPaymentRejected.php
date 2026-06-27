<?php

namespace App\Listeners;

use App\Enums\CancellationType;
use App\Events\PaymentCancelled;
use App\Models\Landlord;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PaymentRejected;
use App\Notifications\SystemAlert;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class NotifyPaymentRejected
{
    /**
     * Handle the PaymentCancelled event.
     *
     * Routes notifications by cancellation type:
     * - SystemDuplicate → notify tenant users + SystemAlert to landlord admins
     * - SystemExpired   → notify tenant users only
     * - Manual          → notify tenant users only
     */
    public function handle(PaymentCancelled $event): void
    {
        match ($event->type) {
            CancellationType::SystemDuplicate => $this->handleDuplicateFraud($event),
            CancellationType::SystemExpired => $this->handleExpiredPayment($event),
            default => $this->handleNormalRejection($event),
        };
    }

    /**
     * SystemDuplicate: possible fraud — notify tenant AND alert admins.
     */
    private function handleDuplicateFraud(PaymentCancelled $event): void
    {
        $this->notifyTenant($event);

        $this->sendSystemAlert(
            'duplicate_reference',
            "Posible fraude: referencia {$event->payment->transaction_id} ya verificada, pago #{$event->payment->id} cancelado.",
            'warning',
        );
    }

    /**
     * SystemExpired: payment expired without reconciliation — notify tenant only.
     */
    private function handleExpiredPayment(PaymentCancelled $event): void
    {
        $this->notifyTenant($event);
    }

    /**
     * Manual or other cancellation — notify tenant only.
     */
    private function handleNormalRejection(PaymentCancelled $event): void
    {
        $this->notifyTenant($event);
    }

    /**
     * Notify the tenant's admin users that their payment was rejected.
     *
     * Switches to the tenant connection, finds users with admin roles,
     * and sends the PaymentRejected notification.
     */
    private function notifyTenant(PaymentCancelled $event): void
    {
        try {
            $tenant = Tenant::on('landlord')->find($event->payment->tenant_id);

            if ($tenant === null) {
                return;
            }

            $tenant->makeCurrent();

            $adminUsers = User::query()
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['owner', 'tenant-admin']))
                ->get();

            if ($adminUsers->isEmpty()) {
                return;
            }

            Notification::send($adminUsers, new PaymentRejected($event->payment, $event->type));
        } catch (\Throwable $e) {
            Log::warning('Failed to notify tenant about rejected payment', [
                'tenant_id' => $event->payment->tenant_id,
                'payment_id' => $event->payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send a SystemAlert to all landlord admin users.
     */
    private function sendSystemAlert(string $type, string $message, string $severity = 'warning'): void
    {
        try {
            $admins = Landlord::all();

            if ($admins->isEmpty()) {
                return;
            }

            Notification::send($admins, new SystemAlert($type, $message, $severity));
        } catch (\Throwable $e) {
            Log::warning('Failed to send system alert', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
