<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TenantContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthController extends Controller
{
    private const LOGIN_MAX_ATTEMPTS = 5;

    private const LOGIN_DECAY_SECONDS = 60;

    public function __construct(private readonly TenantContextService $tenantContext) {}

    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $rateLimitKey = $this->loginRateLimitKey($request, (string) $validated['username']);
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::LOGIN_MAX_ATTEMPTS)) {
            return back()->withErrors(['credentials' => 'Too many login attempts. Please try again in one minute.'])->onlyInput('username');
        }

        $username = Str::lower(trim((string) $validated['username']));
        $user = User::query()->with('tenantMemberships.tenant')->where('email', $username)->first();
        if ($user && Hash::check((string) $validated['password'], (string) $user->password)) {
            RateLimiter::clear($rateLimitKey);
            $role = strtolower(trim((string) ($user->role ?? 'user'))) ?: 'user';
            $tenantId = $role === 'admin' ? null : $this->tenantContext->resolveTenantIdForUser($user);

            session()->put('is_authenticated', true);
            session()->put('is_admin', $role === 'admin');
            session()->put('user_id', $user->id);
            session()->put('user_name', $user->name);
            session()->put('user_email', $user->email);
            session()->put('user_role', $role);
            if ($tenantId !== null) {
                session()->put('current_tenant_id', (string) $tenantId);
            }
            $request->session()->regenerate();

            return redirect()->route($role === 'admin' ? 'admin.overview' : 'dashboard');
        }

        $adminUser = trim((string) config('dashboard.admin_user', ''));
        $adminPass = trim((string) config('dashboard.admin_pass', ''));
        if (
            $adminUser !== '' &&
            $adminPass !== '' &&
            ! ($adminUser === 'admin' && $adminPass === 'change_me_now') &&
            hash_equals($adminUser, $validated['username']) &&
            hash_equals($adminPass, $validated['password'])
        ) {
            RateLimiter::clear($rateLimitKey);
            session()->put('is_authenticated', true);
            session()->put('is_admin', true);
            session()->put('admin_user', $validated['username']);
            session()->put('user_name', $validated['username']);
            session()->put('user_email', $validated['username']);
            session()->put('user_role', 'admin');
            $request->session()->regenerate();

            return redirect()->route('admin.overview');
        }

        RateLimiter::hit($rateLimitKey, self::LOGIN_DECAY_SECONDS);

        return back()->withErrors(['credentials' => 'Invalid credentials'])->onlyInput('username');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function loginRateLimitKey(Request $request, string $username): string
    {
        $normalizedUser = Str::lower(trim($username));

        return 'dashboard-login:'.$normalizedUser.'|'.$request->ip();
    }
}
