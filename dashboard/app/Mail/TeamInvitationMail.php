<?php

namespace App\Mail;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TeamInvitationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $email,
        public readonly string $role,
        public readonly string $acceptUrl,
        public readonly ?User $invitedBy = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You have been invited to VerifySky'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.team-invitation'
        );
    }
}
