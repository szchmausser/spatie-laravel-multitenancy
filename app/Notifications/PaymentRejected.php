<?php

namespace App\Notifications;

use App\Enums\CancellationType;
use App\Models\Payment;
use Illuminate\Notifications\Notification;

class PaymentRejected extends Notification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Payment $payment,
        public CancellationType $cancellationType,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification (in-app).
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'payment_id' => $this->payment->id,
            'cancellation_type' => $this->cancellationType->value,
            'message' => $this->getMessage(),
        ];
    }

    /**
     * Get the localized message based on cancellation type.
     */
    private function getMessage(): string
    {
        return match ($this->cancellationType) {
            CancellationType::SystemDuplicate => 'Su pago ha sido rechazado porque la referencia ya fue verificada anteriormente.',
            CancellationType::SystemExpired => 'Su pago expiró sin conciliación automática.',
            CancellationType::Manual => 'Su pago ha sido cancelado por un administrador.',
            CancellationType::MethodChanged => 'Su pago fue cancelado porque el método de pago cambió.',
        };
    }
}
