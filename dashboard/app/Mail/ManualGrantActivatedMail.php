<?php

namespace App\Mail;

use App\Models\Tenant;
use App\Models\TenantPlanGrant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ManualGrantActivatedMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly TenantPlanGrant $grant
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Manual %s grant activated for %s', strtoupper((string) $this->grant->granted_plan_key), $this->tenant->name)
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.manual-grant-activated'
        );
    }
}
