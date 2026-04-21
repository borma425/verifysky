<?php

namespace App\Jobs;

use App\Mail\UsageThresholdWarningMail;
use App\Models\Tenant;
use App\Models\TenantUsage;
use App\Services\Billing\TenantBillingStatusService;
use App\Services\Mail\TenantOwnerNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendUsageThresholdWarningMailJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $recipientEmails
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $usageId,
        public readonly array $recipientEmails = []
    ) {
        $this->onQueue('mail');
        $this->afterCommit();
    }

    public function handle(
        TenantOwnerNotificationService $owners,
        TenantBillingStatusService $billingStatus
    ): void {
        $tenant = Tenant::query()->find($this->tenantId);
        $usage = TenantUsage::query()->find($this->usageId);

        if (! $tenant instanceof Tenant || ! $usage instanceof TenantUsage) {
            return;
        }

        $recipientEmails = $this->recipientEmails !== []
            ? $this->recipientEmails
            : $owners->ownerEmailsForTenant($tenant);

        if ($recipientEmails === []) {
            return;
        }

        $status = $billingStatus->forTenant($tenant, CarbonImmutable::parse((string) $usage->cycle_start_at, 'UTC')->utc());
        if ($status === null) {
            return;
        }

        foreach (array_values(array_unique(array_filter($recipientEmails))) as $email) {
            Mail::to($email)->send(new UsageThresholdWarningMail($tenant, $usage, $status));
        }
    }
}
