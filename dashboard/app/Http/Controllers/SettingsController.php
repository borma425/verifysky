<?php

namespace App\Http\Controllers;

use App\Models\DashboardSetting;
use App\Services\EdgeShieldService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    private const SENSITIVE_SETTING_KEYS = [
        'cf_api_token',
        'openrouter_api_key',
        'jwt_secret',
        'es_admin_token',
    ];

    public function __construct(private readonly EdgeShieldService $edgeShield)
    {
    }

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

        return view('settings.index', [
            'settings' => $settings,
            'sensitiveConfigured' => $sensitiveConfigured,
            'currentLoginPath' => $this->resolveAdminLoginPath($settings),
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
            'worker_script_name' => ['nullable', 'string', 'max:128'],
            'es_admin_token' => ['nullable', 'string', 'max:500'],
            'es_admin_allowed_ips' => ['nullable', 'string', 'max:2000'],
            'es_admin_rate_limit_per_min' => ['nullable', 'integer', 'min:10', 'max:600'],
            'es_disable_waf_autodeploy' => ['nullable', 'in:on,off'],
            'es_allow_ua_crawler_allowlist' => ['nullable', 'in:on,off'],
            'admin_login_path' => ['nullable', 'string', 'max:120', 'regex:/^[a-zA-Z0-9_\/-]+$/'],
            'es_block_redirect_url' => ['nullable', 'string', 'max:2048', 'regex:/^https?:\/\/.+/i'],
        ]);

        // Normalize toggle fields to explicit "on"/"off"
        $validated['es_disable_waf_autodeploy'] = ($request->input('es_disable_waf_autodeploy') === 'on') ? 'on' : 'off';
        $validated['es_allow_ua_crawler_allowlist'] = ($request->input('es_allow_ua_crawler_allowlist') === 'on') ? 'on' : 'off';

        $existingSensitive = DashboardSetting::query()
            ->whereIn('key', self::SENSITIVE_SETTING_KEYS)
            ->get()
            ->keyBy('key');

        foreach ($validated as $key => $value) {
            if ($key === 'admin_login_path') {
                $value = $this->normalizeLoginPath((string) $value);
            }
            if ($key === 'es_block_redirect_url') {
                $value = trim((string) $value);
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
        $syncSummary = implode(' | ', $sync['logs'] ?? []);

        if (!$sync['ok']) {
            $errorCount = count($sync['errors'] ?? []);
            $firstError = (string) (($sync['errors'][0] ?? 'Cloudflare sync failed'));
            $status = "Settings saved, but Cloudflare sync has {$errorCount} issue(s). First issue: {$firstError}";
            if ($syncSummary !== '') {
                $status .= ' | '.$syncSummary;
            }
            return back()->with('status', $status);
        }

        return back()->with('status', 'Settings saved and synced to Cloudflare successfully. '.$syncSummary);
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
        $candidate = (string) ($settings['admin_login_path'] ?? env('DASHBOARD_LOGIN_PATH', 'wow/login'));
        return $this->normalizeLoginPath($candidate);
    }
}
