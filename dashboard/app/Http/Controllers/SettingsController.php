<?php

namespace App\Http\Controllers;

use App\Models\DashboardSetting;
use App\Services\EdgeShieldService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __construct(private readonly EdgeShieldService $edgeShield)
    {
    }

    public function index(): View
    {
        $settings = DashboardSetting::query()->pluck('value', 'key')->all();

        return view('settings.index', [
            'settings' => $settings,
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
            'es_disable_waf_autodeploy' => ['nullable', 'in:on,off'],
            'es_allow_ua_crawler_allowlist' => ['nullable', 'in:on,off'],
        ]);

        // Normalize toggle fields to explicit "on"/"off"
        $validated['es_disable_waf_autodeploy'] = ($request->input('es_disable_waf_autodeploy') === 'on') ? 'on' : 'off';
        $validated['es_allow_ua_crawler_allowlist'] = ($request->input('es_allow_ua_crawler_allowlist') === 'on') ? 'on' : 'off';

        foreach ($validated as $key => $value) {
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
}
