<?php

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionExpiringWarningMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Subscription $subscription,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your subscription is expiring soon',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.subscription-expiring-warning',
            with: [
                'tenantName' => $this->subscription->tenant->name,
                'planName' => $this->subscription->plan->name,
                'endsAt' => $this->subscription->ends_at->format('F j, Y'),
                'daysRemaining' => max(0, (int) now()->diffInDays($this->subscription->ends_at, false)),
            ],
        );
    }
}
