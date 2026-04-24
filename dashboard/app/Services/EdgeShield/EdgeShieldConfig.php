<?php

namespace App\Services\EdgeShield;

use App\Models\DashboardSetting;

class EdgeShieldConfig
{
    /** @var array<string, mixed>|null */
    private ?array $wranglerMetadata = null;

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
        if ($value === '' && $this->targetEnvironment() === 'staging') {
            return 'verifysky-edge-staging';
        }

        return $value !== '' ? $value : 'verifysky-edge';
    }

    public function saasZoneId(): ?string
    {
        $value = trim((string) config('edgeshield.saas_zone_id', ''));
        if ($value !== '') {
            return $value;
        }

        return $this->dashboardSettingValue('cf_zone_id');
    }

    public function saasCnameTarget(): string
    {
        $target = trim((string) config('edgeshield.saas_cname_target', ''));

        return $target !== '' ? $this->normalizeDomain($target) : 'customers.verifysky.com';
    }

    public function d1DatabaseName(): string
    {
        $configured = trim((string) config('edgeshield.d1_database_name', 'EDGE_SHIELD_DB'));
        if ($this->useLocalD1()) {
            return $this->resolveLocalD1DatabaseName($configured);
        }

        return $this->targetD1DatabaseName() ?? $configured;
    }

    public function configuredD1DatabaseId(): ?string
    {
        $id = trim((string) config('edgeshield.d1_database_id', ''));

        return $id !== '' ? $id : null;
    }

    public function targetD1DatabaseId(): ?string
    {
        if (($configured = $this->configuredD1DatabaseId()) !== null) {
            return $configured;
        }

        $target = $this->targetEnvironment();
        if ($target === 'staging') {
            $matched = $this->environmentMetadataByName('staging');

            return isset($matched['database_ids'][0]) ? (string) $matched['database_ids'][0] : null;
        }

        $metadata = $this->wranglerMetadata();

        return isset($metadata['top_level_database_ids'][0]) ? (string) $metadata['top_level_database_ids'][0] : null;
    }

    public function useLocalD1(): bool
    {
        return strtolower(trim((string) config('edgeshield.d1_mode', 'remote'))) === 'local';
    }

    public function d1DatabaseId(): string
    {
        $id = $this->targetD1DatabaseId();
        if ($id !== null && $id !== '') {
            return $id;
        }

        $metadata = $this->wranglerMetadata();
        $matchedEnvironment = $this->matchedWranglerEnvironment();
        if (($matchedEnvironment['database_ids'][0] ?? null) !== null) {
            return (string) $matchedEnvironment['database_ids'][0];
        }

        if (($metadata['top_level_database_ids'][0] ?? null) !== null) {
            return (string) $metadata['top_level_database_ids'][0];
        }

        return $this->d1DatabaseName();
    }

    public function d1ReadCacheTtl(): int
    {
        return max(0, (int) config('edgeshield.d1_read_cache_ttl', 60));
    }

    public function localD1PersistPath(): string
    {
        $configured = trim((string) config('edgeshield.d1_local_persist_path', ''));

        return $configured !== '' ? rtrim($configured, '/') : storage_path('wrangler-runtime/state');
    }

    public function allowRemoteWranglerD1(): bool
    {
        return (bool) config('edgeshield.d1_allow_wrangler_remote', false);
    }

    public function d1CacheIdentifier(): string
    {
        return $this->targetD1DatabaseId() ?? $this->d1DatabaseName();
    }

    public function wranglerEnvironmentName(): ?string
    {
        if ($this->targetEnvironment() === 'staging') {
            return 'staging';
        }

        $matchedEnvironment = $this->matchedWranglerEnvironment();
        $environmentName = trim((string) ($matchedEnvironment['name'] ?? ''));

        return $environmentName !== '' ? $environmentName : null;
    }

    public function targetEnvironment(): string
    {
        $target = strtolower(trim((string) config('edgeshield.target_env', '')));
        $target = str_replace(['-', ' '], '_', $target);

        return match ($target) {
            'stage' => 'staging',
            'prod_readonly', 'production_read_only', 'prod_read_only' => 'production_readonly',
            'prod' => 'production',
            'staging', 'production_readonly', 'production' => $target,
            default => app()->environment('local') ? 'staging' : 'production',
        };
    }

    public function targetEnvironmentLabel(): string
    {
        return match ($this->targetEnvironment()) {
            'staging' => 'Staging Remote',
            'production_readonly' => 'Production Read-Only',
            default => app()->environment('production') ? 'Production' : 'Production (Local Writes Blocked)',
        };
    }

    public function allowsCloudflareMutations(): bool
    {
        if ($this->targetEnvironment() === 'production_readonly') {
            return false;
        }

        if ($this->targetEnvironment() === 'production' && app()->environment('local')) {
            return false;
        }

        return true;
    }

    public function mutationBlockedError(): string
    {
        if ($this->targetEnvironment() === 'production_readonly') {
            return 'Production is read-only from local dashboard.';
        }

        if ($this->targetEnvironment() === 'production' && app()->environment('local')) {
            return 'Production mutations are blocked from local dashboard. Use EDGE_SHIELD_TARGET_ENV=staging for local write testing.';
        }

        return 'Edge mutations are blocked for this dashboard target.';
    }

    public function canRunD1Query(string $sql): bool
    {
        if ($this->allowsCloudflareMutations()) {
            return true;
        }

        return in_array($this->leadingSqlKeyword($sql), ['SELECT', 'WITH'], true);
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

    private function resolveLocalD1DatabaseName(string $configured): string
    {
        $metadata = $this->wranglerMetadata();
        $knownNames = $metadata['all_database_names'] ?? [];
        if ($configured !== '' && in_array($configured, $knownNames, true)) {
            return $configured;
        }

        $matchedEnvironment = $this->matchedWranglerEnvironment();
        $environmentDatabaseName = trim((string) ($matchedEnvironment['database_names'][0] ?? ''));
        if ($environmentDatabaseName !== '') {
            return $environmentDatabaseName;
        }

        $workerName = $this->workerScriptName();
        $topLevelWorkerName = trim((string) ($metadata['top_level_worker_name'] ?? ''));
        $topLevelDatabaseName = trim((string) ($metadata['top_level_database_names'][0] ?? ''));
        if ($topLevelDatabaseName !== '' && ($topLevelWorkerName === '' || $topLevelWorkerName === $workerName)) {
            return $topLevelDatabaseName;
        }

        if (($knownNames[0] ?? null) !== null) {
            return (string) $knownNames[0];
        }

        return $configured;
    }

    private function targetD1DatabaseName(): ?string
    {
        if ($this->targetEnvironment() === 'staging') {
            $matched = $this->environmentMetadataByName('staging');

            return isset($matched['database_names'][0]) ? (string) $matched['database_names'][0] : null;
        }

        if (in_array($this->targetEnvironment(), ['production', 'production_readonly'], true)) {
            $metadata = $this->wranglerMetadata();

            return isset($metadata['top_level_database_names'][0]) ? (string) $metadata['top_level_database_names'][0] : null;
        }

        return null;
    }

    private function leadingSqlKeyword(string $sql): string
    {
        if (preg_match('/^\s*([a-zA-Z]+)/', $sql, $matches) !== 1) {
            return '';
        }

        return strtoupper($matches[1]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function matchedWranglerEnvironment(): ?array
    {
        $workerName = $this->workerScriptName();
        foreach ($this->wranglerMetadata()['environments'] ?? [] as $environment) {
            $environmentWorkerName = trim((string) ($environment['worker_name'] ?? ''));
            $environmentName = trim((string) ($environment['name'] ?? ''));

            if ($environmentWorkerName !== '' && $environmentWorkerName === $workerName) {
                return $environment;
            }

            if ($environmentName !== '' && str_ends_with($workerName, '-'.$environmentName)) {
                return $environment;
            }
        }

        return null;
    }

    private function environmentMetadataByName(string $name): ?array
    {
        foreach ($this->wranglerMetadata()['environments'] ?? [] as $environment) {
            if (($environment['name'] ?? null) === $name) {
                return $environment;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function wranglerMetadata(): array
    {
        if ($this->wranglerMetadata !== null) {
            return $this->wranglerMetadata;
        }

        $wranglerToml = $this->projectRoot().'/wrangler.toml';
        $contents = is_file($wranglerToml) ? (string) @file_get_contents($wranglerToml) : '';
        if ($contents === '') {
            return $this->wranglerMetadata = [
                'top_level_worker_name' => null,
                'top_level_database_names' => [],
                'top_level_database_ids' => [],
                'all_database_names' => [],
                'environments' => [],
            ];
        }

        $topLevelSection = preg_split('/^\[env\.[^\]]+\]\s*$/m', $contents, 2)[0] ?? $contents;
        preg_match('/^name\s*=\s*"([^"]+)"/m', $topLevelSection, $topLevelNameMatch);
        preg_match_all('/^\s*database_name\s*=\s*"([^"]+)"/m', $topLevelSection, $topLevelDatabaseNameMatches);
        preg_match_all('/^\s*database_id\s*=\s*"([^"]+)"/m', $topLevelSection, $topLevelDatabaseIdMatches);

        preg_match_all('/^\[env\.([^\]]+)\]\s*$(.*?)(?=^\[env\.[^\]]+\]\s*$|\z)/ms', $contents, $environmentMatches, PREG_SET_ORDER);

        $environments = [];
        $allDatabaseNames = array_values(array_unique(array_filter($topLevelDatabaseNameMatches[1] ?? [])));
        foreach ($environmentMatches as $match) {
            $body = (string) ($match[2] ?? '');
            preg_match('/^name\s*=\s*"([^"]+)"/m', $body, $environmentWorkerNameMatch);
            preg_match_all('/^\s*database_name\s*=\s*"([^"]+)"/m', $body, $environmentDatabaseNameMatches);
            preg_match_all('/^\s*database_id\s*=\s*"([^"]+)"/m', $body, $environmentDatabaseIdMatches);

            $databaseNames = array_values(array_unique(array_filter($environmentDatabaseNameMatches[1] ?? [])));
            $databaseIds = array_values(array_unique(array_filter($environmentDatabaseIdMatches[1] ?? [])));
            $allDatabaseNames = array_values(array_unique(array_merge($allDatabaseNames, $databaseNames)));

            $environments[] = [
                'name' => trim((string) ($match[1] ?? '')),
                'worker_name' => trim((string) ($environmentWorkerNameMatch[1] ?? '')),
                'database_names' => $databaseNames,
                'database_ids' => $databaseIds,
            ];
        }

        return $this->wranglerMetadata = [
            'top_level_worker_name' => trim((string) ($topLevelNameMatch[1] ?? '')),
            'top_level_database_names' => array_values(array_unique(array_filter($topLevelDatabaseNameMatches[1] ?? []))),
            'top_level_database_ids' => array_values(array_unique(array_filter($topLevelDatabaseIdMatches[1] ?? []))),
            'all_database_names' => $allDatabaseNames,
            'environments' => $environments,
        ];
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
