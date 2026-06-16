<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ManualNotification extends Notification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $message,
        public ?string $title = null,
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
            'title' => $this->title,
            'message' => $this->message,
        ];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title ?? 'New notification');

        if ($this->title) {
            $mail->line("**{$this->title}**");
        }

        return $mail
            ->line($this->message)
            ->line('Thank you for using our service!');
    }
}
