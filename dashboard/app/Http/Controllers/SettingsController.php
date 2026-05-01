<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\EdgeShieldService;
use App\Support\TenantLoginPath;
use App\Support\UserFacingErrorSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __construct(private readonly EdgeShieldService $edgeShield) {}

    public function index(): View
    {
        if (request()->routeIs('admin.*')) {
            return view('settings.platform', [
                'layout' => 'layouts.admin',
                'settingsUpdateRoute' => 'admin.settings.update',
                'platformSettings' => $this->platformSettings(),
            ]);
        }

        $tenant = $this->currentTenantOrFail();
        $user = $this->currentUserOrFail();

        return view('settings.account', [
            'layout' => 'layouts.app',
            'tenant' => $tenant,
            'user' => $user,
            'settingsUpdateRoute' => 'settings.update',
            'currentLoginSlug' => TenantLoginPath::slugFromPath((string) $tenant->login_path),
            'loginUrlPrefix' => rtrim(url('/'.TenantLoginPath::ACCOUNT_PREFIX), '/').'/',
            'currentLoginUrl' => url('/'.((string) $tenant->login_path)),
            'avatarUrl' => $this->publicStorageUrl($user->avatar_path),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        if ($request->routeIs('admin.*')) {
            return $this->syncPlatformSettings();
        }

        $tenant = $this->currentTenantOrFail();
        $user = $this->currentUserOrFail();
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($user->getKey())],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],
            'current_password' => ['nullable', 'string'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'name' => ['required', 'string', 'max:190'],
            'login_slug' => ['required', 'string', 'max:80'],
        ]);

        $newEmail = trim(strtolower((string) $validated['email']));
        $emailChanged = $newEmail !== trim(strtolower((string) $user->email));
        $passwordChanged = trim((string) ($validated['password'] ?? '')) !== '';
        if (($emailChanged || $passwordChanged) && ! Hash::check((string) ($validated['current_password'] ?? ''), (string) $user->password)) {
            $errors = new MessageBag;
            $errors->add('current_password', 'The current password is required to change your email or password.');

            return back()->withErrors($errors)->withInput($request->except(['current_password', 'password', 'password_confirmation']));
        }

        $loginSlug = TenantLoginPath::normalizeSlug((string) $validated['login_slug']);
        $loginPath = TenantLoginPath::forSlug($loginSlug);
        $errors = new MessageBag;
        if (TenantLoginPath::isReservedSlug($loginSlug)) {
            $errors->add('login_slug', 'This login slug is reserved.');
        }
        if ($loginPath === TenantLoginPath::normalize((string) config('dashboard.login_path', 'wow/login'))) {
            $errors->add('login_slug', 'This login slug is reserved for platform administration.');
        }
        if (Tenant::query()
            ->where('login_path', $loginPath)
            ->whereKeyNot($tenant->getKey())
            ->exists()) {
            $errors->add('login_slug', 'This login slug is already in use.');
        }

        if ($errors->any()) {
            return back()->withErrors($errors)->withInput();
        }

        $avatarPath = $user->avatar_path;
        if ($request->boolean('remove_avatar')) {
            if ($avatarPath) {
                Storage::disk('public')->delete((string) $avatarPath);
            }
            $avatarPath = null;
        }
        if ($request->hasFile('avatar')) {
            if ($avatarPath) {
                Storage::disk('public')->delete((string) $avatarPath);
            }
            $avatarPath = $request->file('avatar')->store('avatars', 'public');
        }

        $user->forceFill([
            'email' => $newEmail,
            'avatar_path' => $avatarPath,
        ]);
        if ($passwordChanged) {
            $user->password = (string) $validated['password'];
        }
        $user->save();

        $tenant->forceFill([
            'name' => trim((string) $validated['name']),
            'login_path' => $loginPath,
        ])->save();

        session()->put('user_email', $user->email);
        session()->put('user_avatar_path', $user->avatar_path);

        return back()->with('status', 'Account settings saved.');
    }

    private function syncPlatformSettings(): RedirectResponse
    {
        $sync = $this->edgeShield->syncCloudflareFromDashboardSettings();
        if (! $sync['ok']) {
            $errorCount = count($sync['errors'] ?? []);
            $firstError = UserFacingErrorSanitizer::sanitize(
                $this->stripAnsi((string) ($sync['errors'][0] ?? 'Edge sync failed.')),
                'Edge service sync failed. Please review environment configuration and try again.'
            );
            $message = "Environment settings were read, but edge service sync failed ({$errorCount} issue(s)). First issue: {$firstError}";

            return back()->with('error', $message);
        }

        $message = 'Environment settings synced to edge services successfully.';
        if ($this->hasAlreadySyncedRoutes($sync)) {
            $message .= ' Routes are already up to date.';
        }

        return back()->with('status', $message);
    }

    private function currentTenantOrFail(): Tenant
    {
        $tenantId = trim((string) session('current_tenant_id', ''));
        abort_if($tenantId === '', 404);

        $tenant = Tenant::query()->find($tenantId);
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    private function currentUserOrFail(): User
    {
        $userId = trim((string) session('user_id', ''));
        abort_if($userId === '', 404);

        $user = User::query()->find($userId);
        abort_unless($user instanceof User, 404);

        return $user;
    }

    private function publicStorageUrl(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        return asset('storage/'.ltrim($path, '/'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function platformSettings(): array
    {
        $runtime = (array) config('edgeshield.runtime', []);

        return [
            ['label' => 'Admin Login Path', 'value' => TenantLoginPath::normalize((string) config('dashboard.login_path', 'wow/login')), 'secret' => false],
            ['label' => 'Worker Script Name', 'value' => (string) config('edgeshield.worker_name', 'verifysky-edge'), 'secret' => false],
            ['label' => 'Cloudflare Account ID', 'value' => (string) config('edgeshield.cloudflare_account_id', ''), 'secret' => false],
            ['label' => 'Cloudflare Zone ID', 'value' => (string) config('edgeshield.saas_zone_id', ''), 'secret' => false],
            ['label' => 'OpenRouter Model', 'value' => (string) ($runtime['openrouter_model'] ?? ''), 'secret' => false],
            ['label' => 'OpenRouter Fallback Models', 'value' => (string) ($runtime['openrouter_fallback_models'] ?? ''), 'secret' => false],
            ['label' => 'Cloudflare API Token', 'configured' => trim((string) config('edgeshield.cloudflare_api_token', '')) !== '', 'secret' => true],
            ['label' => 'OpenRouter API Key', 'configured' => trim((string) ($runtime['openrouter_api_key'] ?? '')) !== '', 'secret' => true],
            ['label' => 'JWT Secret', 'configured' => trim((string) ($runtime['jwt_secret'] ?? '')) !== '', 'secret' => true],
            ['label' => 'Meter Secret', 'configured' => trim((string) ($runtime['meter_secret'] ?? '')) !== '', 'secret' => true],
            ['label' => 'ES Admin Token', 'configured' => trim((string) ($runtime['es_admin_token'] ?? '')) !== '', 'secret' => true],
            ['label' => 'Turnstile Strict', 'value' => (string) ($runtime['es_turnstile_strict'] ?? 'on'), 'secret' => false],
            ['label' => 'Strict Context Binding', 'value' => (string) ($runtime['es_strict_context_binding'] ?? 'off'), 'secret' => false],
            ['label' => 'ES Admin Rate Limit / min', 'value' => (string) ($runtime['es_admin_rate_limit_per_min'] ?? ''), 'secret' => false],
        ];
    }

    private function stripAnsi(string $text): string
    {
        $plain = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $plain) ?? $plain);
    }

    private function hasAlreadySyncedRoutes(array $sync): bool
    {
        $logs = $sync['logs'] ?? [];
        if (! is_array($logs)) {
            return false;
        }

        foreach ($logs as $log) {
            $line = strtolower((string) $log);
            if (str_contains($line, 'route-sync') && str_contains($line, 'already_synced')) {
                return true;
            }
        }

        return false;
    }
}
