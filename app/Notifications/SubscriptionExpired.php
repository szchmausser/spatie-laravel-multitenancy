<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionExpired extends Notification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Subscription $subscription,
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
        return [
            'subscription_id' => $this->subscription->id,
            'tenant_id' => $this->subscription->tenant_id,
            'plan_name' => $this->subscription->plan->name,
            'ends_at' => $this->subscription->ends_at?->toIso8601String(),
            'message' => "Your subscription to {$this->subscription->plan->name} has expired.",
        ];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your subscription has expired')
            ->line("Your subscription to {$this->subscription->plan->name} has expired.")
            ->line("Expiration date: {$this->subscription->ends_at?->format('F j, Y')}")
            ->action('Renew Subscription', url('/billing'))
            ->line('Please renew your subscription to continue accessing premium features.');
    }
}
