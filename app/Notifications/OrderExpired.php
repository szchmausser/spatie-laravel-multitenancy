<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderExpired extends Notification
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
            'message' => "Your pending order #{$this->order->id} has expired.",
        ];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $buyableName = $this->order->buyable?->name ?? 'a product';

        return (new MailMessage)
            ->subject('Your order has expired')
            ->line("Your pending order #{$this->order->id} for {$buyableName} has expired.")
            ->line('Amount: $'.number_format($this->order->total_cents / 100, 2))
            ->action('Place New Order', url('/billing'))
            ->line('You can place a new order at any time.');
    }
}
