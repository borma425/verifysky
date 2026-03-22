<?php

namespace App\Http\Controllers;

use App\Models\TrapLead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarketingController extends Controller
{
    public function index(): View
    {
        return view('marketing.home');
    }

    public function storeLead(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc,dns', 'max:190'],
            'domain' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'required' => 'This field is required.',
            'email' => 'Please enter a valid email address.',
            'max' => 'This field exceeds the maximum allowed length.',
            'string' => 'This field must be valid text.',
        ]);

        TrapLead::query()->create([
            'name' => trim((string) $validated['name']),
            'email' => strtolower(trim((string) $validated['email'])),
            'domain' => strtolower(trim((string) $validated['domain'])),
            'company' => trim((string) ($validated['company'] ?? '')),
            'notes' => trim((string) ($validated['notes'] ?? '')),
            'source' => 'landing_spa',
            'ip_address' => $this->resolveClientIp($request),
            'user_agent' => (string) $request->userAgent(),
        ]);

        return back()->with('status', 'Your request has been submitted successfully. Our team will contact you shortly.');
    }

    private function resolveClientIp(Request $request): string
    {
        // Cloudflare provides a direct visitor IP header.
        $cfIp = trim((string) $request->header('CF-Connecting-IP', ''));
        if ($cfIp !== '' && filter_var($cfIp, FILTER_VALIDATE_IP)) {
            return $cfIp;
        }

        // Fallback for reverse proxies/load balancers.
        $xff = trim((string) $request->header('X-Forwarded-For', ''));
        if ($xff !== '') {
            $parts = array_map('trim', explode(',', $xff));
            foreach ($parts as $ip) {
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        $ip = trim((string) $request->ip());
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        return trim((string) $request->server('REMOTE_ADDR', '')) ?: '0.0.0.0';
    }
}
