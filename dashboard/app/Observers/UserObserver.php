<?php

namespace App\Observers;

use App\Jobs\SendWelcomeCustomerMailJob;
use App\Models\User;

class UserObserver
{
    public function created(User $user): void
    {
        if (strtolower(trim((string) $user->role)) === 'admin') {
            return;
        }

        SendWelcomeCustomerMailJob::dispatch((int) $user->getKey());
    }
}
