<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentVerified extends Notification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Order $order,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Get the array representation of the notification (in-app).
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $buyable = $this->order->buyable;

        return [
            'order_id' => $this->order->id,
            'tenant_id' => $this->order->tenant_id,
            'total_cents' => $this->order->total_cents,
            'buyable_type' => $this->order->buyable_type,
            'buyable_name' => $buyable?->name,
            'url' => "/billing/orders/{$this->order->id}",
            'message' => "Your payment for order #{$this->order->id} has been verified. Service is now active.",
        ];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $buyableName = $this->order->buyable?->name ?? 'your purchase';

        return (new MailMessage)
            ->subject('Payment verified — service active')
            ->line("Your payment for order #{$this->order->id} ({$buyableName}) has been verified.")
            ->line('Your service is now active. You can start using it immediately.')
            ->action('Go to Dashboard', url('/dashboard'))
            ->line('Thank you for your purchase!');
    }
}
