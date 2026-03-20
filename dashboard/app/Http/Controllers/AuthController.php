<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthController extends Controller
{
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

        $adminUser = (string) env('DASHBOARD_ADMIN_USER', 'admin');
        $adminPass = (string) env('DASHBOARD_ADMIN_PASS', 'change_me_now');

        if (
            hash_equals($adminUser, $validated['username']) &&
            hash_equals($adminPass, $validated['password'])
        ) {
            session()->put('is_admin', true);
            session()->put('admin_user', $validated['username']);
            $request->session()->regenerate();

            return redirect()->route('dashboard');
        }

        return back()->withErrors(['credentials' => 'Invalid credentials'])->onlyInput('username');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}

