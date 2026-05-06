<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantLoginPath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AuthController extends Controller
{
    private const LOGIN_MAX_ATTEMPTS = 5;

    private const LOGIN_DECAY_SECONDS = 60;

    public function show(): View
    {
        return view('auth.login', [
            'loginAction' => route('admin.login.submit'),
            'loginContext' => 'admin',
            'tenant' => null,
        ]);
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
        $user = User::query()->where('email', $username)->first();
        if ($user && Hash::check((string) $validated['password'], (string) $user->password)) {
            $role = strtolower(trim((string) ($user->role ?? 'user'))) ?: 'user';
            if ($role !== 'admin') {
                return $this->invalidLogin($request, $rateLimitKey);
            }

            RateLimiter::clear($rateLimitKey);

            session()->put('is_authenticated', true);
            session()->put('is_admin', true);
            session()->put('user_id', $user->id);
            session()->put('user_name', $user->name);
            session()->put('user_email', $user->email);
            session()->put('user_avatar_path', $user->avatar_path);
            session()->put('user_role', 'admin');
            $request->session()->regenerate();

            return redirect()->route('admin.overview');
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
            session()->forget('user_avatar_path');
            session()->put('user_role', 'admin');
            $request->session()->regenerate();

            return redirect()->route('admin.overview');
        }

        return $this->invalidLogin($request, $rateLimitKey);
    }

    public function showTenantLogin(string $tenantLoginPath): View
    {
        $tenant = $this->tenantForLoginPathOrFail($tenantLoginPath);

        return view('auth.login', [
            'loginAction' => url('/'.$tenant->login_path),
            'loginContext' => 'tenant',
            'tenant' => $tenant,
        ]);
    }

    public function loginTenant(Request $request, string $tenantLoginPath): RedirectResponse
    {
        $tenant = $this->tenantForLoginPathOrFail($tenantLoginPath);
        $validated = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $rateLimitKey = $this->loginRateLimitKey($request, (string) $validated['username']);
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::LOGIN_MAX_ATTEMPTS)) {
            return back()->withErrors(['credentials' => 'Too many login attempts. Please try again in one minute.'])->onlyInput('username');
        }

        $username = Str::lower(trim((string) $validated['username']));
        $user = User::query()
            ->with('tenantMemberships:id,user_id,tenant_id')
            ->where('email', $username)
            ->first();

        if (! $user || ! Hash::check((string) $validated['password'], (string) $user->password)) {
            return $this->invalidLogin($request, $rateLimitKey);
        }

        $role = strtolower(trim((string) ($user->role ?? 'user'))) ?: 'user';
        if ($role === 'admin') {
            return $this->invalidLogin($request, $rateLimitKey);
        }

        $belongsToTenant = $user->tenantMemberships
            ->contains(fn ($membership): bool => (string) $membership->tenant_id === (string) $tenant->getKey());
        if (! $belongsToTenant) {
            return $this->invalidLogin($request, $rateLimitKey);
        }

        if ($user->email_verified_at === null) {
            return back()
                ->withErrors(['credentials' => 'Please activate your account from the email we sent before signing in.'])
                ->onlyInput('username');
        }

        RateLimiter::clear($rateLimitKey);
        session()->put('is_authenticated', true);
        session()->put('is_admin', false);
        session()->put('user_id', $user->id);
        session()->put('user_name', $user->name);
        session()->put('user_email', $user->email);
        session()->put('user_avatar_path', $user->avatar_path);
        session()->put('user_role', $role);
        session()->put('current_tenant_id', (string) $tenant->getKey());
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        $redirectTo = $this->logoutRedirectUrl();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to($redirectTo);
    }

    private function loginRateLimitKey(Request $request, string $username): string
    {
        $normalizedUser = Str::lower(trim($username));

        return 'dashboard-login:'.$normalizedUser.'|'.$request->path().'|'.$request->ip();
    }

    private function tenantForLoginPathOrFail(string $tenantLoginPath): Tenant
    {
        $path = TenantLoginPath::normalize($tenantLoginPath);
        if (TenantLoginPath::isReserved($path)) {
            throw new NotFoundHttpException;
        }

        $tenant = Tenant::query()->where('login_path', $path)->first();
        if (! $tenant instanceof Tenant) {
            throw new NotFoundHttpException;
        }

        return $tenant;
    }

    private function logoutRedirectUrl(): string
    {
        if ((bool) session('is_admin')) {
            return route('admin.login');
        }

        $tenantId = trim((string) session('current_tenant_id', ''));
        if ($tenantId !== '') {
            $tenant = Tenant::query()->find($tenantId);
            if ($tenant instanceof Tenant && trim((string) $tenant->login_path) !== '') {
                return url('/'.$tenant->login_path);
            }
        }

        return url('/');
    }

    private function invalidLogin(Request $request, string $rateLimitKey): RedirectResponse
    {
        RateLimiter::hit($rateLimitKey, self::LOGIN_DECAY_SECONDS);

        return back()->withErrors(['credentials' => 'Invalid credentials'])->onlyInput('username');
    }
}
