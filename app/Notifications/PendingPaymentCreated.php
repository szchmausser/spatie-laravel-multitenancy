<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PendingPaymentCreated extends Notification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Payment $payment,
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
        $order = $this->payment->order;
        $tenant = Tenant::on('landlord')->find($this->payment->tenant_id);

        return [
            'payment_id' => $this->payment->id,
            'order_id' => $this->payment->order_id,
            'tenant_id' => $this->payment->tenant_id,
            'tenant_name' => $tenant?->name,
            'amount_cents' => $this->payment->amount_cents,
            'payment_method' => $this->payment->payment_method,
            'buyable_type' => $order?->buyable_type,
            'buyable_name' => $order?->buyable?->name,
            'message' => 'New pending payment of $'.number_format($this->payment->amount_cents / 100, 2).' from '.($tenant?->name ?? 'tenant #'.$this->payment->tenant_id).' requires verification.',
        ];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $tenant = Tenant::on('landlord')->find($this->payment->tenant_id);
        $tenantName = $tenant?->name ?? 'a tenant';

        return (new MailMessage)
            ->subject('New payment requires verification')
            ->line('A new payment of $'.number_format($this->payment->amount_cents / 100, 2)." from {$tenantName} is pending verification.")
            ->line('Payment method: '.$this->payment->payment_method)
            ->action('Verificar Pago', url('/admin/orders/'.$this->payment->order_id))
            ->line('Please review and verify this payment at your earliest convenience.');
    }
}
