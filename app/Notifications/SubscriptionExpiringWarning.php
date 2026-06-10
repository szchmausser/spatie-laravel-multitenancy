<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionExpiringWarning extends Notification
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
            'ends_at' => $this->subscription->ends_at->toIso8601String(),
            'days_remaining' => max(0, (int) now()->diffInDays($this->subscription->ends_at, false)),
            'message' => "Your subscription to {$this->subscription->plan->name} is expiring soon.",
        ];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $daysRemaining = max(0, (int) now()->diffInDays($this->subscription->ends_at, false));

        return (new MailMessage)
            ->subject('Your subscription is expiring soon')
            ->line("Your subscription to {$this->subscription->plan->name} will expire in {$daysRemaining} day(s).")
            ->line("Expiration date: {$this->subscription->ends_at->format('F j, Y')}")
            ->action('Renew Subscription', url('/billing'))
            ->line('Thank you for using our service!');
    }
}
