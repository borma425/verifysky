<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AccountActivationController extends Controller
{
    public function __invoke(Request $request, User $user, string $hash): RedirectResponse
    {
        if (! hash_equals(sha1((string) $user->email), $hash)) {
            throw new HttpException(403);
        }

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $tenant = $user->tenantMemberships()
            ->with('tenant:id,login_path')
            ->oldest('id')
            ->get()
            ->pluck('tenant')
            ->first(fn ($tenant): bool => $tenant instanceof Tenant && trim((string) $tenant->login_path) !== '');

        if (! $tenant instanceof Tenant) {
            return redirect()->route('home')
                ->with('status', 'Your account is active. Please contact support if you cannot find your login path.');
        }

        return redirect()->to(url('/'.$tenant->login_path))
            ->with('status', 'Your account is active. You can sign in now.');
    }
}
