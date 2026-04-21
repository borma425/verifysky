<?php

namespace App\Jobs;

use App\Mail\WelcomeCustomerMail;
use App\Models\User;
use App\Services\Mail\TenantOwnerNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendWelcomeCustomerMailJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId)
    {
        $this->onQueue('mail');
        $this->afterCommit();
    }

    public function handle(TenantOwnerNotificationService $owners): void
    {
        $user = User::query()
            ->with('tenantMemberships.tenant:id,name')
            ->find($this->userId);

        if (! $user instanceof User || strtolower(trim((string) $user->role)) === 'admin') {
            return;
        }

        $recipientEmails = $owners->ownerEmailsForUser($user);
        if ($recipientEmails === []) {
            return;
        }

        $tenantNames = $user->tenantMemberships
            ->pluck('tenant.name')
            ->map(static fn (mixed $name): string => trim((string) $name))
            ->filter()
            ->unique()
            ->values()
            ->all();

        foreach ($recipientEmails as $email) {
            Mail::to($email)->send(new WelcomeCustomerMail($user, $tenantNames));
        }
    }
}
