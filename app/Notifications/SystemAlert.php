<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class SystemAlert extends Notification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $type,
        public string $message,
        public string $severity = 'warning',
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
            'category' => 'system',
            'type' => $this->type,
            'message' => $this->message,
            'severity' => $this->severity,
        ];
    }
}
