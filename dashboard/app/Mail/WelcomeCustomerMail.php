<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WelcomeCustomerMail extends Mailable
{
    use Queueable;

    /**
     * @param  array<int, string>  $tenantNames
     */
    public function __construct(
        public readonly User $user,
        public readonly array $tenantNames
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New customer access has been created'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.welcome-customer'
        );
    }
}
