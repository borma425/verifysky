<?php

namespace App\Services\EdgeShield;

use App\Models\DashboardSetting;

class WorkerSecretSyncService
{
    private ?array $settingsCache = null;

    public function __construct(
        private readonly EdgeShieldConfig $config,
        private readonly WranglerProcessRunner $runner,
        private readonly DomainConfigService $domains,
        private readonly WorkerRouteService $workerRoutes,
        private readonly SaasSecurityService $saasSecurity
    ) {}

    public function syncFromDashboardSettings(): array
    {
        $secrets = [
            'CF_API_TOKEN' => $this->setting('CF_API_TOKEN'),
            'OPENROUTER_API_KEY' => $this->setting('OPENROUTER_API_KEY'),
            'JWT_SECRET' => $this->setting('JWT_SECRET'),
            'METER_SECRET' => $this->setting('METER_SECRET'),
            'ES_ADMIN_TOKEN' => $this->setting('ES_ADMIN_TOKEN'),
        ];

        $logs = [];
        $errors = [];

        $requiredSecrets = ['CF_API_TOKEN', 'JWT_SECRET', 'ES_ADMIN_TOKEN'];
        foreach ($requiredSecrets as $requiredSecret) {
            if (! is_string($secrets[$requiredSecret]) || trim($secrets[$requiredSecret]) === '') {
                return [
                    'ok' => false,
                    'logs' => [],
                    'errors' => [$requiredSecret.' is required in Dashboard settings before sync.'],
                    'deploy' => null,
                ];
            }
        }

        if (strlen((string) $secrets['JWT_SECRET']) < 32) {
            return [
                'ok' => false,
                'logs' => [],
                'errors' => ['JWT_SECRET must be at least 32 characters before sync.'],
                'deploy' => null,
            ];
        }

        if (is_string($secrets['METER_SECRET']) && trim($secrets['METER_SECRET']) !== '' && strlen((string) $secrets['METER_SECRET']) < 32) {
            return [
                'ok' => false,
                'logs' => [],
                'errors' => ['METER_SECRET must be at least 32 characters when configured.'],
                'deploy' => null,
            ];
        }

        foreach ($secrets as $name => $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $result = $this->syncSecret($name, $value);
            $logs[] = sprintf('secret:%s exit=%s', $name, (string) ($result['exit_code'] ?? 'n/a'));
            if (! $result['ok']) {
                $raw = (string) (($result['error'] ?? '') ?: ($result['output'] ?? 'secret sync failed'));
                $errors[] = sprintf('%s => %s', $name, $this->compactErrorMessage($raw));
            }
        }

        $openrouterModel = $this->setting('OPENROUTER_MODEL') ?: 'qwen/qwen3-next-80b-a3b-instruct:free';
        $openrouterFallbacks = $this->setting('OPENROUTER_FALLBACK_MODELS') ?: 'openai/gpt-oss-120b:free,nvidia/nemotron-3-super:free';
        $disableWaf = $this->setting('ES_DISABLE_WAF_AUTODEPLOY') ?: 'on';
        $allowUaCompat = $this->setting('ES_ALLOW_UA_CRAWLER_ALLOWLIST') ?: 'off';
        $turnstileStrict = $this->setting('ES_TURNSTILE_STRICT') ?: 'on';
        $strictContextBinding = $this->setting('ES_STRICT_CONTEXT_BINDING') ?: 'off';
        $adminAllowedIps = $this->setting('ES_ADMIN_ALLOWED_IPS') ?: '';
        $adminRatePerMin = $this->setting('ES_ADMIN_RATE_LIMIT_PER_MIN') ?: '60';
        $blockRedirectUrl = $this->setting('ES_BLOCK_REDIRECT_URL') ?: '';

        $deployCmd = $this->config->wranglerBin().' deploy --keep-vars'
            .' --var '.escapeshellarg('OPENROUTER_MODEL:'.$openrouterModel)
            .' --var '.escapeshellarg('OPENROUTER_FALLBACK_MODELS:'.$openrouterFallbacks)
            .' --var '.escapeshellarg('ES_DISABLE_WAF_AUTODEPLOY:'.$disableWaf)
            .' --var '.escapeshellarg('ES_ALLOW_UA_CRAWLER_ALLOWLIST:'.$allowUaCompat)
            .' --var '.escapeshellarg('ES_TURNSTILE_STRICT:'.$turnstileStrict)
            .' --var '.escapeshellarg('ES_STRICT_CONTEXT_BINDING:'.$strictContextBinding)
            .' --var '.escapeshellarg('ES_ADMIN_ALLOWED_IPS:'.$adminAllowedIps)
            .' --var '.escapeshellarg('ES_ADMIN_RATE_LIMIT_PER_MIN:'.$adminRatePerMin)
            .' --var '.escapeshellarg('ES_BLOCK_REDIRECT_URL:'.$blockRedirectUrl);

        $deploy = $this->runner->runInProject($deployCmd, 240);
        $logs[] = 'deploy-with-vars exit='.(string) ($deploy['exit_code'] ?? 'n/a');
        if (! $deploy['ok']) {
            $raw = (string) (($deploy['error'] ?? '') ?: ($deploy['output'] ?? 'deploy failed'));
            $errors[] = 'deploy => '.$this->compactErrorMessage($raw);
        } else {
            $routeSync = $this->syncAllActiveRoutes();
            if (! $routeSync['ok']) {
                $errors[] = 'route-sync => '.((string) ($routeSync['error'] ?? 'route sync failed'));
            } elseif (! empty($routeSync['synced'])) {
                $logs[] = 'route-sync => '.implode(', ', $routeSync['synced']);
            }
        }

        return ['ok' => count($errors) === 0, 'logs' => $logs, 'errors' => $errors, 'deploy' => $deploy];
    }

    private function syncSecret(string $name, string $value): array
    {
        $tmp = tempnam(storage_path('app'), 'edge-secret-');
        if ($tmp === false) {
            return ['ok' => false, 'exit_code' => 1, 'output' => '', 'error' => 'Failed to create temporary file for secret sync.', 'command' => ''];
        }

        try {
            file_put_contents($tmp, $value);
            $cmd = sprintf('%s secret put %s < %s', $this->config->wranglerBin(), escapeshellarg($name), escapeshellarg($tmp));

            return $this->runner->runInProject($cmd, 120);
        } finally {
            @unlink($tmp);
        }
    }

    private function syncAllActiveRoutes(): array
    {
        $query = $this->domains->listActiveDomainsForRouteSync();
        if (! $query['ok']) {
            return ['ok' => false, 'error' => $query['error'], 'synced' => []];
        }

        $synced = [];
        $errors = [];
        foreach ($query['rows'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $domain = trim((string) ($row['domain_name'] ?? ''));
            $zoneId = trim((string) ($row['zone_id'] ?? ''));
            if ($domain === '' || $zoneId === '') {
                continue;
            }

            $cache = $this->saasSecurity->ensureCacheRuleForEdgeShield($zoneId, $domain);
            $result = $this->workerRoutes->ensureWorkerRoute($zoneId, $domain, $cache);
            if ($result['ok']) {
                $synced[] = $domain.': '.($result['action'] ?? 'synced');
            } else {
                $errors[] = $domain.': '.($result['error'] ?? 'sync failed');
            }
        }

        return [
            'ok' => count($errors) === 0,
            'error' => count($errors) ? implode(' | ', $errors) : null,
            'synced' => $synced,
        ];
    }

    private function setting(string $key): ?string
    {
        if ($this->settingsCache === null) {
            $this->settingsCache = DashboardSetting::query()
                ->get()
                ->mapWithKeys(static fn (DashboardSetting $setting): array => [$setting->key => $setting->value])
                ->all();
        }

        $value = trim((string) ($this->settingsCache[$this->normalizeDashboardKey($key)] ?? ''));
        if ($value === '') {
            $value = trim((string) ($this->settingsCache[$key] ?? ''));
        }

        return $value !== '' ? $value : null;
    }

    private function normalizeDashboardKey(string $key): string
    {
        return strtolower($key);
    }

    private function compactErrorMessage(string $error): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $error) ?? $error);
        if ($normalized === '') {
            return 'unknown error';
        }
        if (str_contains($normalized, 'GLIBCXX_') || str_contains($normalized, 'CXXABI_')) {
            return 'Node runtime mismatch detected (GLIBCXX/CXXABI). Check server Node/XAMPP runtime linkage.';
        }

        return mb_strimwidth($normalized, 0, 220, '...');
    }
}
