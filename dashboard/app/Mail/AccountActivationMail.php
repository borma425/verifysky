<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AccountActivationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly User $user,
        public readonly string $loginUrl,
        public readonly string $loginPath,
        public readonly string $activationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Activate your VerifySky account'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account-activation'
        );
    }
}
