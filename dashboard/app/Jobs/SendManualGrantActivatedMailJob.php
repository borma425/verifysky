<?php

namespace App\Jobs;

use App\Mail\ManualGrantActivatedMail;
use App\Models\Tenant;
use App\Models\TenantPlanGrant;
use App\Services\Mail\TenantOwnerNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendManualGrantActivatedMailJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $recipientEmails
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $grantId,
        public readonly array $recipientEmails = []
    ) {
        $this->onQueue('mail');
        $this->afterCommit();
    }

    public function handle(TenantOwnerNotificationService $owners): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        $grant = TenantPlanGrant::query()->find($this->grantId);

        if (! $tenant instanceof Tenant || ! $grant instanceof TenantPlanGrant) {
            return;
        }

        $recipientEmails = $this->recipientEmails !== []
            ? $this->recipientEmails
            : $owners->ownerEmailsForTenant($tenant);

        foreach (array_values(array_unique(array_filter($recipientEmails))) as $email) {
            Mail::to($email)->send(new ManualGrantActivatedMail($tenant, $grant));
        }
    }
}
