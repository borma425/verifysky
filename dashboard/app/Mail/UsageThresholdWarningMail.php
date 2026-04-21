<?php

namespace App\Mail;

use App\Models\Tenant;
use App\Models\TenantUsage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class UsageThresholdWarningMail extends Mailable
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $billingStatus
     */
    public function __construct(
        public readonly Tenant $tenant,
        public readonly TenantUsage $usage,
        public readonly array $billingStatus
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Usage warning for %s', $this->tenant->name)
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.usage-threshold-warning'
        );
    }
}
