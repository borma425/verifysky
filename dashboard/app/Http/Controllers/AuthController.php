<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthController extends Controller
{
    private const LOGIN_MAX_ATTEMPTS = 5;
    private const LOGIN_DECAY_SECONDS = 60;

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

        $adminUser = trim((string) env('DASHBOARD_ADMIN_USER', ''));
        $adminPass = trim((string) env('DASHBOARD_ADMIN_PASS', ''));

        if ($adminUser === '' || $adminPass === '' || ($adminUser === 'admin' && $adminPass === 'change_me_now')) {
            return back()->withErrors(['credentials' => 'Dashboard credentials are not configured securely.'])->onlyInput('username');
        }

        $rateLimitKey = $this->loginRateLimitKey($request, (string) $validated['username']);
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::LOGIN_MAX_ATTEMPTS)) {
            return back()->withErrors(['credentials' => 'Too many login attempts. Please try again in one minute.'])->onlyInput('username');
        }

        if (
            hash_equals($adminUser, $validated['username']) &&
            hash_equals($adminPass, $validated['password'])
        ) {
            RateLimiter::clear($rateLimitKey);
            session()->put('is_admin', true);
            session()->put('admin_user', $validated['username']);
            $request->session()->regenerate();

            return redirect()->route('dashboard');
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
