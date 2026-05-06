<?php

namespace App\Jobs;

use App\Mail\AccountActivationMail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class SendAccountActivationMailJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId)
    {
        $this->onQueue('mail');
        $this->afterCommit();
    }

    public function handle(): void
    {
        $user = User::query()
            ->with('tenantMemberships.tenant:id,login_path')
            ->find($this->userId);

        if (! $user instanceof User || strtolower(trim((string) $user->role)) === 'admin') {
            return;
        }

        $tenant = $user->tenantMemberships
            ->pluck('tenant')
            ->first(fn ($tenant): bool => $tenant instanceof Tenant && trim((string) $tenant->login_path) !== '');

        if (! $tenant instanceof Tenant) {
            return;
        }

        $activationUrl = URL::temporarySignedRoute(
            'account.activate',
            now()->addDays(7),
            [
                'user' => $user->getKey(),
                'hash' => sha1((string) $user->email),
            ]
        );

        Mail::to((string) $user->email)->send(new AccountActivationMail(
            user: $user,
            loginUrl: url('/'.$tenant->login_path),
            loginPath: '/'.$tenant->login_path,
            activationUrl: $activationUrl,
        ));
    }
}
