<?php

namespace App\Http\Controllers;

use App\Models\DashboardSetting;
use App\Services\EdgeShieldService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    private const DEFAULT_WORKER_SCRIPT_NAME = 'verifysky-edge';

    private const SENSITIVE_SETTING_KEYS = [
        'cf_api_token',
        'openrouter_api_key',
        'jwt_secret',
        'meter_secret',
        'es_admin_token',
    ];

    public function __construct(private readonly EdgeShieldService $edgeShield) {}

    public function index(): View
    {
        $settings = DashboardSetting::query()
            ->get()
            ->mapWithKeys(fn (DashboardSetting $setting): array => [$setting->key => $setting->value])
            ->all();

        $sensitiveConfigured = [];
        foreach (self::SENSITIVE_SETTING_KEYS as $key) {
            $sensitiveConfigured[$key] = trim((string) ($settings[$key] ?? '')) !== '';
            unset($settings[$key]);
        }

        $settings['worker_script_name'] = $this->normalizeWorkerScriptName((string) ($settings['worker_script_name'] ?? ''));

        return view('settings.index', [
            'settings' => $settings,
            'sensitiveConfigured' => $sensitiveConfigured,
            'currentLoginPath' => $this->resolveAdminLoginPath($settings),
            'layout' => request()->routeIs('admin.*') ? 'layouts.admin' : 'layouts.app',
            'settingsUpdateRoute' => request()->routeIs('admin.*') ? 'admin.settings.update' : 'settings.update',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'openrouter_model' => ['nullable', 'string', 'max:255'],
            'openrouter_fallback_models' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'cf_api_token' => ['nullable', 'string', 'max:500'],
            'cf_account_id' => ['nullable', 'string', 'max:128'],
            'openrouter_api_key' => ['nullable', 'string', 'max:500'],
            'jwt_secret' => ['nullable', 'string', 'max:500'],
            'meter_secret' => ['nullable', 'string', 'max:500'],
            'worker_script_name' => ['nullable', 'string', 'max:128'],
            'es_admin_token' => ['nullable', 'string', 'max:500'],
            'es_admin_rate_limit_per_min' => ['nullable', 'integer', 'min:10', 'max:600'],
            'es_disable_waf_autodeploy' => ['nullable', 'in:on,off'],
            'es_allow_ua_crawler_allowlist' => ['nullable', 'in:on,off'],
            'es_turnstile_strict' => ['nullable', 'in:on,off'],
            'es_strict_context_binding' => ['nullable', 'in:on,off'],
            'admin_login_path' => ['nullable', 'string', 'max:120', 'regex:/^[a-zA-Z0-9_\/-]+$/'],
        ]);

        // Normalize toggle fields to explicit "on"/"off"
        $validated['es_disable_waf_autodeploy'] = ($request->input('es_disable_waf_autodeploy') === 'on') ? 'on' : 'off';
        $validated['es_allow_ua_crawler_allowlist'] = ($request->input('es_allow_ua_crawler_allowlist') === 'on') ? 'on' : 'off';
        $validated['es_turnstile_strict'] = ($request->input('es_turnstile_strict', 'on') === 'off') ? 'off' : 'on';
        $validated['es_strict_context_binding'] = ($request->input('es_strict_context_binding') === 'on') ? 'on' : 'off';

        $existingSensitive = DashboardSetting::query()
            ->whereIn('key', self::SENSITIVE_SETTING_KEYS)
            ->get()
            ->keyBy('key');

        foreach ($validated as $key => $value) {
            if ($key === 'admin_login_path') {
                $value = $this->normalizeLoginPath((string) $value);
            }
            if ($key === 'worker_script_name') {
                $value = $this->normalizeWorkerScriptName((string) $value);
            }
            if (in_array($key, self::SENSITIVE_SETTING_KEYS, true) && trim((string) ($value ?? '')) === '') {
                $existing = $existingSensitive->get($key);
                if ($existing instanceof DashboardSetting && trim((string) $existing->value) !== '') {
                    continue;
                }
            }
            DashboardSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => (string) ($value ?? '')]
            );
        }

        $sync = $this->edgeShield->syncCloudflareFromDashboardSettings();
        if (! $sync['ok']) {
            $errorCount = count($sync['errors'] ?? []);
            $firstError = $this->stripAnsi((string) ($sync['errors'][0] ?? 'Cloudflare sync failed.'));
            $message = "Settings saved, but Cloudflare sync failed ({$errorCount} issue(s)). First issue: {$firstError}";

            return back()->with('error', $message);
        }

        $message = 'Settings saved and synced to Cloudflare successfully.';
        if ($this->hasAlreadySyncedRoutes($sync)) {
            $message .= ' Routes are already up to date.';
        }

        return back()->with('status', $message);
    }

    private function normalizeLoginPath(string $path): string
    {
        $candidate = trim(strtolower($path));
        $candidate = ltrim($candidate, '/');
        $candidate = preg_replace('/[^a-z0-9\/_-]/', '', $candidate) ?? '';
        $candidate = preg_replace('#/+#', '/', $candidate) ?? '';
        $candidate = trim($candidate, '/');

        $reserved = ['', 'login', 'logout', 'dashboard', 'domains', 'logs', 'settings', 'actions', 'api'];
        if (in_array($candidate, $reserved, true)) {
            return 'wow/login';
        }

        return $candidate;
    }

    private function resolveAdminLoginPath(array $settings): string
    {
        $candidate = (string) ($settings['admin_login_path'] ?? config('dashboard.login_path', 'wow/login'));

        return $this->normalizeLoginPath($candidate);
    }

    private function stripAnsi(string $text): string
    {
        $plain = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $plain) ?? $plain);
    }

    private function normalizeWorkerScriptName(string $value): string
    {
        $candidate = trim($value);

        return match ($candidate) {
            '', 'verifysky-edge-staging', 'verifysky-edge-production' => self::DEFAULT_WORKER_SCRIPT_NAME,
            default => $candidate,
        };
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
