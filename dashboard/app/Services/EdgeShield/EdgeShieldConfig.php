<?php

namespace App\Services\EdgeShield;

use App\Models\DashboardSetting;

class EdgeShieldConfig
{
    public function projectRoot(): string
    {
        $configuredRoot = trim((string) config('edgeshield.root', ''));
        if ($configuredRoot !== '') {
            return rtrim($configuredRoot, '/');
        }

        $candidates = [
            base_path('../worker'),
            base_path('../../verifysky/worker'),
            base_path('..'),
            dirname(base_path()),
        ];

        $defaultRoot = null;
        foreach ($candidates as $candidate) {
            $resolved = @realpath($candidate);
            if (! $resolved || ! is_dir($resolved)) {
                continue;
            }
            if (is_file($resolved.'/wrangler.toml') && is_dir($resolved.'/src')) {
                $defaultRoot = $resolved;
                break;
            }
            $defaultRoot ??= $resolved;
        }

        return rtrim((string) ($defaultRoot ?: dirname(base_path())), '/');
    }

    public function wranglerBin(): string
    {
        $configured = trim((string) config('edgeshield.wrangler_bin', ''));
        if ($configured !== '') {
            return $configured;
        }

        $nodeBinDir = $this->nodeBinDir();
        if ($nodeBinDir) {
            $npx = rtrim($nodeBinDir, '/').'/npx';
            if (is_file($npx) && is_executable($npx)) {
                return escapeshellarg($npx).' wrangler';
            }
        }

        return 'npx wrangler';
    }

    public function nodeBinDir(): ?string
    {
        $dir = trim((string) config('edgeshield.node_bin_dir', ''));
        if ($dir !== '') {
            return $dir;
        }

        $candidates = glob(base_path('.runtime/node-*/bin'));
        if (is_array($candidates) && count($candidates) > 0) {
            sort($candidates);
            $picked = end($candidates);
            if (is_dir($picked)) {
                return $picked;
            }
        }

        return null;
    }

    public function cloudflareApiToken(): ?string
    {
        $value = trim((string) config('edgeshield.cloudflare_api_token', ''));
        if ($value !== '') {
            return $value;
        }

        return $this->dashboardSettingValue('cf_api_token');
    }

    public function cloudflareAccountId(): ?string
    {
        $value = trim((string) config('edgeshield.cloudflare_account_id', ''));
        if ($value !== '') {
            return $value;
        }

        return $this->dashboardSettingValue('cf_account_id');
    }

    public function workerScriptName(): string
    {
        $value = trim((string) config('edgeshield.worker_name', ''));

        return $value !== '' ? $value : 'verifysky-edge';
    }

    public function saasZoneId(): ?string
    {
        $value = trim((string) config('edgeshield.saas_zone_id', ''));

        return $value !== '' ? $value : null;
    }

    public function saasCnameTarget(): string
    {
        $target = trim((string) config('edgeshield.saas_cname_target', ''));

        return $target !== '' ? $this->normalizeDomain($target) : 'customers.verifysky.com';
    }

    public function d1DatabaseName(): string
    {
        return trim((string) config('edgeshield.d1_database_name', 'EDGE_SHIELD_DB'));
    }

    public function configuredD1DatabaseId(): ?string
    {
        $id = trim((string) config('edgeshield.d1_database_id', ''));

        return $id !== '' ? $id : null;
    }

    public function useLocalD1(): bool
    {
        return strtolower(trim((string) config('edgeshield.d1_mode', 'remote'))) === 'local';
    }

    public function d1DatabaseId(): string
    {
        $id = trim((string) config('edgeshield.d1_database_id', ''));
        if ($id !== '') {
            return $id;
        }

        $wranglerToml = $this->projectRoot().'/wrangler.toml';
        $contents = is_file($wranglerToml) ? (string) @file_get_contents($wranglerToml) : '';
        if ($contents !== '' && preg_match('/database_id\s*=\s*"([^"]+)"/', $contents, $matches)) {
            return $matches[1];
        }

        return $this->d1DatabaseName();
    }

    public function d1ReadCacheTtl(): int
    {
        return max(0, (int) config('edgeshield.d1_read_cache_ttl', 60));
    }

    public function allowRemoteWranglerD1(): bool
    {
        return (bool) config('edgeshield.d1_allow_wrangler_remote', false);
    }

    public function d1CacheIdentifier(): string
    {
        return $this->configuredD1DatabaseId() ?? $this->d1DatabaseName();
    }

    public function workerRuntimeEnvironment(): array
    {
        $runtime = is_array(config('edgeshield.runtime', [])) ? config('edgeshield.runtime', []) : [];

        return array_filter([
            'OPENROUTER_MODEL' => trim((string) ($runtime['openrouter_model'] ?? '')),
            'OPENROUTER_FALLBACK_MODELS' => trim((string) ($runtime['openrouter_fallback_models'] ?? '')),
            'OPENROUTER_API_KEY' => trim((string) ($runtime['openrouter_api_key'] ?? '')),
            'JWT_SECRET' => trim((string) ($runtime['jwt_secret'] ?? '')),
            'METER_SECRET' => trim((string) ($runtime['meter_secret'] ?? '')),
            'ES_ADMIN_TOKEN' => trim((string) ($runtime['es_admin_token'] ?? '')),
            'ES_DISABLE_WAF_AUTODEPLOY' => trim((string) ($runtime['es_disable_waf_autodeploy'] ?? '')),
            'ES_ALLOW_UA_CRAWLER_ALLOWLIST' => trim((string) ($runtime['es_allow_ua_crawler_allowlist'] ?? '')),
            'ES_TURNSTILE_STRICT' => trim((string) ($runtime['es_turnstile_strict'] ?? 'on')),
            'ES_STRICT_CONTEXT_BINDING' => trim((string) ($runtime['es_strict_context_binding'] ?? 'off')),
            'ES_ADMIN_ALLOWED_IPS' => trim((string) ($runtime['es_admin_allowed_ips'] ?? '')),
            'ES_ADMIN_RATE_LIMIT_PER_MIN' => trim((string) ($runtime['es_admin_rate_limit_per_min'] ?? '')),
            'ES_BLOCK_REDIRECT_URL' => trim((string) ($runtime['es_block_redirect_url'] ?? '')),
        ], static fn (string $value): bool => $value !== '');
    }

    private function normalizeDomain(string $domainName): string
    {
        $domain = strtolower(trim($domainName));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = explode('/', $domain, 2)[0];

        return trim($domain);
    }

    private function dashboardSettingValue(string $key): ?string
    {
        try {
            $setting = DashboardSetting::query()->where('key', $key)->first();
        } catch (\Throwable) {
            return null;
        }

        $value = trim((string) ($setting?->value ?? ''));

        return $value !== '' ? $value : null;
    }
}
