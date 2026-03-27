<?php

namespace App\Services;

use App\Models\DashboardSetting;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

class EdgeShieldService
{
    private const CF_API_BASE = 'https://api.cloudflare.com/client/v4';
    private ?array $settingsCache = null;
    private ?bool $procOpenAvailable = null;

    public function projectRoot(): string
    {
        $configuredRoot = trim((string) env('EDGE_SHIELD_ROOT', ''));
        if ($configuredRoot !== '') {
            return rtrim($configuredRoot, '/');
        }

        $candidates = [
            base_path('../worker'),
            base_path('../../cloudflare_antibots/worker'),
            base_path('..'),
            dirname(base_path()),
        ];

        $defaultRoot = null;
        foreach ($candidates as $candidate) {
            // Some shared-hosting setups enforce open_basedir and may block
            // probing sibling paths outside the dashboard root.
            $resolved = @realpath($candidate);
            if (!$resolved || !is_dir($resolved)) {
                continue;
            }
            // Prefer the actual worker directory.
            if (is_file($resolved.'/wrangler.toml') && is_dir($resolved.'/src')) {
                $defaultRoot = $resolved;
                break;
            }
            if ($defaultRoot === null) {
                $defaultRoot = $resolved;
            }
        }

        $defaultRoot = $defaultRoot ?: dirname(base_path());
        return rtrim((string) env('EDGE_SHIELD_ROOT', $defaultRoot), '/');
    }

    public function wranglerBin(): string
    {
        $configured = trim((string) env('WRANGLER_BIN', ''));
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
        $dir = trim((string) env('NODE_BIN_DIR', ''));
        if ($dir !== '') {
            return $dir;
        }

        // Auto-detect dashboard-managed Node runtime (preferred in XAMPP setups).
        $candidates = glob(base_path('.runtime/node-*/bin'));
        if (is_array($candidates) && count($candidates) > 0) {
            sort($candidates);
            $picked = end($candidates);
            if (is_string($picked) && is_dir($picked)) {
                return $picked;
            }
        }

        return null;
    }

    /**
     * XAMPP can inject old C++ runtime libs via LD_LIBRARY_PATH and break Node/Wrangler.
     * We explicitly unset LD_LIBRARY_PATH for wrangler/node subprocesses.
     */
    private function sanitizeCommandForNode(string $command): string
    {
        $runtimeHome = storage_path('wrangler-runtime');
        if (!is_dir($runtimeHome)) {
            @mkdir($runtimeHome, 0775, true);
        }
        $xdgConfig = $runtimeHome.'/.config';
        if (!is_dir($xdgConfig)) {
            @mkdir($xdgConfig, 0775, true);
        }

        $envPrefix = 'env -u LD_LIBRARY_PATH -u LD_PRELOAD -u LIBRARY_PATH';
        // Force-empty these vars as a second safety layer for Apache/XAMPP environments.
        $envPrefix .= " LD_LIBRARY_PATH=''";
        $envPrefix .= " LD_PRELOAD=''";
        $envPrefix .= " LIBRARY_PATH=''";
        $envPrefix .= ' HOME='.escapeshellarg($runtimeHome);
        $envPrefix .= ' XDG_CONFIG_HOME='.escapeshellarg($xdgConfig);
        $envPrefix .= ' WRANGLER_LOG_PATH='.escapeshellarg($runtimeHome.'/logs');

        $nodeBinDir = $this->nodeBinDir();
        if ($nodeBinDir !== null) {
            $currentPath = (string) getenv('PATH');
            $parts = array_filter(explode(':', $currentPath), fn (string $p): bool => $p !== '');
            $safeParts = array_values(array_filter($parts, fn (string $p): bool => !str_starts_with($p, '/opt/lampp')));
            $safePath = $nodeBinDir.':'.implode(':', $safeParts ?: ['/usr/local/bin', '/usr/bin', '/bin']);
            $escaped = escapeshellarg($safePath);
            $envPrefix .= " PATH={$escaped}";
        }

        $token = $this->cloudflareApiToken();
        if ($token !== null) {
            $envPrefix .= ' CLOUDFLARE_API_TOKEN='.escapeshellarg($token);
        }

        // Worker runtime settings: Dashboard is the source of truth.
        $openrouterModel = trim((string) ($this->getDashboardSetting('openrouter_model') ?? ''));
        if ($openrouterModel !== '') {
            $envPrefix .= ' OPENROUTER_MODEL='.escapeshellarg($openrouterModel);
        }

        $openrouterFallbacks = trim((string) ($this->getDashboardSetting('openrouter_fallback_models') ?? ''));
        if ($openrouterFallbacks !== '') {
            $envPrefix .= ' OPENROUTER_FALLBACK_MODELS='.escapeshellarg($openrouterFallbacks);
        }

        $openrouterApiKey = trim((string) ($this->getDashboardSetting('openrouter_api_key') ?? ''));
        if ($openrouterApiKey !== '') {
            $envPrefix .= ' OPENROUTER_API_KEY='.escapeshellarg($openrouterApiKey);
        }

        $jwtSecret = trim((string) ($this->getDashboardSetting('jwt_secret') ?? ''));
        if ($jwtSecret !== '') {
            $envPrefix .= ' JWT_SECRET='.escapeshellarg($jwtSecret);
        }

        $esAdminToken = trim((string) ($this->getDashboardSetting('es_admin_token') ?? ''));
        if ($esAdminToken !== '') {
            $envPrefix .= ' ES_ADMIN_TOKEN='.escapeshellarg($esAdminToken);
        }

        $esDisableWaf = trim((string) ($this->getDashboardSetting('es_disable_waf_autodeploy') ?? ''));
        if ($esDisableWaf !== '') {
            $envPrefix .= ' ES_DISABLE_WAF_AUTODEPLOY='.escapeshellarg($esDisableWaf);
        }

        $esCrawlerCompat = trim((string) ($this->getDashboardSetting('es_allow_ua_crawler_allowlist') ?? ''));
        if ($esCrawlerCompat !== '') {
            $envPrefix .= ' ES_ALLOW_UA_CRAWLER_ALLOWLIST='.escapeshellarg($esCrawlerCompat);
        }

        $esAdminAllowedIps = trim((string) ($this->getDashboardSetting('es_admin_allowed_ips') ?? ''));
        if ($esAdminAllowedIps !== '') {
            $envPrefix .= ' ES_ADMIN_ALLOWED_IPS='.escapeshellarg($esAdminAllowedIps);
        }

        $esAdminRatePerMin = trim((string) ($this->getDashboardSetting('es_admin_rate_limit_per_min') ?? ''));
        if ($esAdminRatePerMin !== '') {
            $envPrefix .= ' ES_ADMIN_RATE_LIMIT_PER_MIN='.escapeshellarg($esAdminRatePerMin);
        }
        $blockRedirectUrl = trim((string) ($this->getDashboardSetting('es_block_redirect_url') ?? ''));
        if ($blockRedirectUrl !== '') {
            $envPrefix .= ' ES_BLOCK_REDIRECT_URL='.escapeshellarg($blockRedirectUrl);
        }

        return $envPrefix.' '.$command;
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

    private function cloudflareApiToken(): ?string
    {
        $settingToken = trim((string) ($this->getDashboardSetting('cf_api_token') ?? ''));
        if ($settingToken !== '') {
            return $settingToken;
        }

        // Optional bootstrap fallback from dashboard app env only.
        $envToken = trim((string) env('CLOUDFLARE_API_TOKEN', ''));
        if ($envToken !== '') {
            return $envToken;
        }
        $legacyEnvToken = trim((string) env('CF_API_TOKEN', ''));
        return $legacyEnvToken !== '' ? $legacyEnvToken : null;
    }

    private function cloudflareAccountId(): ?string
    {
        $settingAccountId = trim((string) ($this->getDashboardSetting('cf_account_id') ?? ''));
        if ($settingAccountId !== '') {
            return $settingAccountId;
        }

        // Optional bootstrap fallback from dashboard app env only.
        $envAccountId = trim((string) env('CLOUDFLARE_ACCOUNT_ID', ''));
        if ($envAccountId !== '') {
            return $envAccountId;
        }
        $legacyEnvAccountId = trim((string) env('CF_ACCOUNT_ID', ''));
        return $legacyEnvAccountId !== '' ? $legacyEnvAccountId : null;
    }

    private function workerScriptName(): string
    {
        $settingName = trim((string) ($this->getDashboardSetting('worker_script_name') ?? ''));
        if ($settingName !== '') {
            return $settingName;
        }

        // Optional bootstrap fallback from dashboard app env only.
        $envName = trim((string) env('EDGE_SHIELD_WORKER_NAME', ''));
        return $envName !== '' ? $envName : 'edge-shield';
    }

    private function cloudflareRequest(string $method, string $path, array $query = [], ?array $json = null): array
    {
        $token = $this->cloudflareApiToken();
        if ($token === null) {
            return ['ok' => false, 'error' => 'Cloudflare API token is missing. Add CF API Token in Settings.', 'result' => null];
        }

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withToken($token)
                ->send($method, self::CF_API_BASE.$path, [
                    'query' => $query,
                    'json' => $json,
                ]);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'Cloudflare API request failed: '.$e->getMessage(),
                'result' => null,
            ];
        }

        if (!$response->ok()) {
            $errorMessage = null;
            $payload = $response->json();
            if (is_array($payload)) {
                $errorMessage = $payload['errors'][0]['message'] ?? null;
            }
            return [
                'ok' => false,
                'error' => $errorMessage
                    ? 'Cloudflare API HTTP error: '.$response->status().' ('.$errorMessage.')'
                    : 'Cloudflare API HTTP error: '.$response->status(),
                'result' => null,
            ];
        }

        $data = $response->json();
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Unexpected Cloudflare API response.', 'result' => null];
        }

        if (($data['success'] ?? false) !== true) {
            $firstError = $data['errors'][0]['message'] ?? null;
            return [
                'ok' => false,
                'error' => $firstError ? 'Cloudflare API error: '.$firstError : 'Cloudflare API reported failure.',
                'result' => null,
            ];
        }

        return ['ok' => true, 'error' => null, 'result' => $data['result'] ?? null];
    }

    public function autoProvisionDomainConfig(
        string $domainName,
        ?string $zoneId = null,
        ?string $turnstileSiteKey = null,
        ?string $turnstileSecret = null
    ): array {
        $domain = $this->normalizeDomain($domainName);
        $resolvedZoneId = trim((string) ($zoneId ?? ''));
        $resolvedSiteKey = trim((string) ($turnstileSiteKey ?? ''));
        $resolvedSecret = trim((string) ($turnstileSecret ?? ''));
        $zoneAccountId = null;

        if ($resolvedZoneId === '') {
            $zoneLookup = $this->cloudflareRequest('GET', '/zones', [
                'name' => $domain,
                'status' => 'active',
                'page' => 1,
                'per_page' => 1,
                'match' => 'all',
            ]);

            if (!$zoneLookup['ok']) {
                return ['ok' => false, 'error' => $zoneLookup['error']];
            }

            $zone = is_array($zoneLookup['result'][0] ?? null) ? $zoneLookup['result'][0] : null;
            if (!$zone || !is_string($zone['id'] ?? null)) {
                return ['ok' => false, 'error' => 'Zone not found in Cloudflare for this domain. Make sure the domain is added and active in the same account.'];
            }

            $resolvedZoneId = $zone['id'];
            $zoneAccountId = is_string($zone['account']['id'] ?? null) ? $zone['account']['id'] : null;
        }

        if ($resolvedSiteKey === '' || $resolvedSecret === '') {
            $accountId = $this->cloudflareAccountId() ?: $zoneAccountId;
            if (!$accountId) {
                return ['ok' => false, 'error' => 'Cloudflare Account ID is required to auto-create Turnstile widget. Add it in Settings as CF Account ID.'];
            }

            $widgetName = 'Edge Shield - '.$domain;
            $widgetCreate = $this->cloudflareRequest(
                'POST',
                '/accounts/'.$accountId.'/challenges/widgets',
                [],
                [
                    'name' => $widgetName,
                    'domains' => $this->turnstileAllowedDomains($domain),
                    // Worker challenge runtime expects invisible mode.
                    'mode' => 'invisible',
                ]
            );

            if (!$widgetCreate['ok']) {
                return ['ok' => false, 'error' => $widgetCreate['error']];
            }

            $widget = is_array($widgetCreate['result']) ? $widgetCreate['result'] : [];
            if ($resolvedSiteKey === '') {
                $resolvedSiteKey = (string) ($widget['sitekey'] ?? '');
            }
            if ($resolvedSecret === '') {
                $resolvedSecret = (string) ($widget['secret'] ?? '');
            }

            // Some API flows may not return secret directly; rotate to retrieve a fresh secret.
            if ($resolvedSiteKey !== '' && $resolvedSecret === '') {
                $rotate = $this->cloudflareRequest(
                    'POST',
                    '/accounts/'.$accountId.'/challenges/widgets/'.$resolvedSiteKey.'/rotate_secret',
                    [],
                    ['invalidate_immediately' => false]
                );
                if ($rotate['ok']) {
                    $rotateWidget = is_array($rotate['result']) ? $rotate['result'] : [];
                    $resolvedSecret = (string) ($rotateWidget['secret'] ?? $resolvedSecret);
                }
            }
        }

        if ($resolvedZoneId === '' || $resolvedSiteKey === '' || $resolvedSecret === '') {
            return ['ok' => false, 'error' => 'Automatic provisioning completed partially. Missing Zone ID or Turnstile keys.'];
        }

        // --- NEW: Automatically create the Cache Rule to prevent Click Fraud on Edge Cache ---
        $cacheRuleResult = $this->ensureCacheRuleForEdgeShield($resolvedZoneId, $domain);
        // We log or handle the error, but we don't fail the whole provisioning if it fails
        // so the user still gets the worker route and turnstile.
        $finalError = null;
        if (!$cacheRuleResult['ok']) {
            $finalError = 'Turnstile created, but Cache Rule failed: ' . ($cacheRuleResult['error'] ?? 'Unknown error');
        }

        return [
            'ok' => true,
            'error' => $finalError,
            'domain_name' => $domain,
            'zone_id' => $resolvedZoneId,
            'turnstile_sitekey' => $resolvedSiteKey,
            'turnstile_secret' => $resolvedSecret,
            'cache_rule_action' => $cacheRuleResult['action'] ?? null,
        ];
    }

    public function removeDomainSecurityArtifacts(
        string $zoneId,
        string $domainName,
        ?string $turnstileSiteKey = null
    ): array {
        $zone = trim($zoneId);
        $domain = $this->normalizeDomain($domainName);
        $siteKey = trim((string) ($turnstileSiteKey ?? ''));

        if ($zone === '' || $domain === '') {
            return ['ok' => false, 'error' => 'Zone ID or domain is empty.', 'details' => []];
        }

        $details = [];
        $routeRemoval = $this->removeWorkerRoutes($zone, $domain);
        if ($routeRemoval['ok']) {
            $details[] = 'Worker routes removed: '.($routeRemoval['action'] ?? 'none');
        } else {
            $details[] = 'Worker route cleanup failed: '.($routeRemoval['error'] ?? 'unknown error');
        }

        if ($siteKey !== '') {
            $widgetRemoval = $this->deleteTurnstileWidget($zone, $siteKey);
            if ($widgetRemoval['ok']) {
                $details[] = 'Turnstile widget removed.';
            } else {
                $details[] = 'Turnstile widget cleanup failed: '.($widgetRemoval['error'] ?? 'unknown error');
            }
        } else {
            $details[] = 'Turnstile widget key missing; widget cleanup skipped.';
        }

        $ok = $routeRemoval['ok'] && ($siteKey === '' || ($widgetRemoval['ok'] ?? false));
        return [
            'ok' => $ok,
            'error' => $ok ? null : implode(' | ', $details),
            'details' => $details,
        ];
    }

    public function ensureWorkerRoute(string $zoneId, string $domainName): array
    {
        $zone = trim($zoneId);
        $domain = $this->normalizeDomain($domainName);
        if ($zone === '' || $domain === '') {
            return ['ok' => false, 'error' => 'Zone ID or domain is empty.'];
        }

        // Sync Cache Rules at the same time as worker routes
        $cacheRuleResult = $this->ensureCacheRuleForEdgeShield($zone, $domain);
        $cacheRuleFailed = !$cacheRuleResult['ok'];
        $cacheRuleError = $cacheRuleResult['error'] ?? null;

        $primaryDomain = $domain;
        $secondaryDomain = str_starts_with($domain, 'www.')
            ? substr($domain, 4)
            : 'www.'.$domain;
        $patterns = array_values(array_unique([
            $primaryDomain.'/*',
            $secondaryDomain !== '' ? $secondaryDomain.'/*' : null,
        ]));
        $script = $this->workerScriptName();

        $routes = [];
        $page = 1;
        while (true) {
            $list = $this->cloudflareRequest('GET', '/zones/'.$zone.'/workers/routes', [
                'page' => $page,
                'per_page' => 100,
            ]);
            if (!$list['ok']) {
                return ['ok' => false, 'error' => $list['error']];
            }

            $pageRoutes = is_array($list['result']) ? $list['result'] : [];
            $routes = array_merge($routes, $pageRoutes);
            if (count($pageRoutes) < 100) {
                break;
            }
            $page++;
            if ($page > 20) {
                break;
            }
        }
        $actions = [];

        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || trim($pattern) === '') {
                continue;
            }

            $matched = null;
            foreach ($routes as $route) {
                if (!is_array($route)) {
                    continue;
                }
                if (($route['pattern'] ?? null) === $pattern) {
                    $matched = $route;
                    break;
                }
            }

            if ($matched && is_string($matched['script'] ?? null) && $matched['script'] === $script) {
                $actions[] = $pattern.':already_synced';
                continue;
            }

            if ($matched && is_string($matched['id'] ?? null)) {
                $update = $this->cloudflareRequest(
                    'PUT',
                    '/zones/'.$zone.'/workers/routes/'.$matched['id'],
                    [],
                    ['pattern' => $pattern, 'script' => $script]
                );
                if (!$update['ok']) {
                    return ['ok' => false, 'error' => $update['error']];
                }
                $actions[] = $pattern.':updated';
                continue;
            }

            $create = $this->cloudflareRequest(
                'POST',
                '/zones/'.$zone.'/workers/routes',
                [],
                ['pattern' => $pattern, 'script' => $script]
            );
            if (!$create['ok']) {
                if (str_contains(strtolower((string) $create['error']), '409')) {
                    $actions[] = $pattern.':already_exists';
                    continue;
                }
                return ['ok' => false, 'error' => $create['error']];
            }
            $actions[] = $pattern.':created';
        }

        // Append Cache Rule sync status
        if (isset($cacheRuleResult['action'])) {
            $actions[] = 'cache_rule:' . $cacheRuleResult['action'];
        }

        return [
            'ok' => !$cacheRuleFailed, 
            'error' => $cacheRuleFailed ? 'Worker routes synced, but Cache Rule sync failed: ' . $cacheRuleError : null, 
            'action' => implode(', ', $actions)
        ];
    }

    public function removeWorkerRoutes(string $zoneId, string $domainName): array
    {
        $zone = trim($zoneId);
        $domain = $this->normalizeDomain($domainName);
        if ($zone === '' || $domain === '') {
            return ['ok' => false, 'error' => 'Zone ID or domain is empty.'];
        }

        $primaryDomain = $domain;
        $secondaryDomain = str_starts_with($domain, 'www.')
            ? substr($domain, 4)
            : 'www.'.$domain;
        $patterns = array_values(array_unique([
            $primaryDomain.'/*',
            $secondaryDomain !== '' ? $secondaryDomain.'/*' : null,
        ]));
        $script = $this->workerScriptName();

        $routes = [];
        $page = 1;
        while (true) {
            $list = $this->cloudflareRequest('GET', '/zones/'.$zone.'/workers/routes', [
                'page' => $page,
                'per_page' => 100,
            ]);
            if (!$list['ok']) {
                return ['ok' => false, 'error' => $list['error']];
            }

            $pageRoutes = is_array($list['result']) ? $list['result'] : [];
            $routes = array_merge($routes, $pageRoutes);
            if (count($pageRoutes) < 100 || $page > 20) {
                break;
            }
            $page++;
        }

        $actions = [];
        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || trim($pattern) === '') {
                continue;
            }

            $matched = null;
            foreach ($routes as $route) {
                if (!is_array($route)) {
                    continue;
                }
                if (($route['pattern'] ?? null) === $pattern && ($route['script'] ?? null) === $script) {
                    $matched = $route;
                    break;
                }
            }

            if (!$matched || !is_string($matched['id'] ?? null) || trim((string) $matched['id']) === '') {
                $actions[] = $pattern.':not_found';
                continue;
            }

            $delete = $this->cloudflareRequest('DELETE', '/zones/'.$zone.'/workers/routes/'.$matched['id']);
            if (!$delete['ok']) {
                return ['ok' => false, 'error' => $delete['error']];
            }

            $actions[] = $pattern.':deleted';
        }

        return ['ok' => true, 'error' => null, 'action' => implode(', ', $actions)];
    }

    public function syncAllActiveDomainRoutes(): array
    {
        $query = $this->queryD1(
            "SELECT domain_name, zone_id FROM domain_configs WHERE status = 'active' ORDER BY domain_name"
        );
        if (!$query['ok']) {
            return ['ok' => false, 'error' => $query['error'] ?: 'Failed to load active domains', 'synced' => []];
        }

        $rows = $this->parseWranglerJson($query['output'])[0]['results'] ?? [];
        if (!is_array($rows)) {
            return ['ok' => false, 'error' => 'Unexpected D1 response while syncing routes', 'synced' => []];
        }

        $synced = [];
        $errors = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $domain = trim((string) ($row['domain_name'] ?? ''));
            $zoneId = trim((string) ($row['zone_id'] ?? ''));
            if ($domain === '' || $zoneId === '') {
                continue;
            }

            $result = $this->ensureWorkerRoute($zoneId, $domain);
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

    public function ensureSecurityModeColumn(): void
    {
        // Backward compatibility for older D1 schema.
        // If the column already exists, D1 will return an error and we ignore it.
        $this->queryD1(
            "ALTER TABLE domain_configs ADD COLUMN security_mode TEXT NOT NULL DEFAULT 'balanced'"
        );
        $this->queryD1(
            "UPDATE domain_configs SET security_mode = 'balanced' WHERE security_mode IS NULL OR TRIM(security_mode) = ''"
        );
    }

    public function ensureSecurityLogsDomainColumn(): void
    {
        // Backward compatibility for older D1 schema.
        // If the column already exists, D1 will return an error and we ignore it.
        $this->queryD1(
            "ALTER TABLE security_logs ADD COLUMN domain_name TEXT"
        );
        $this->queryD1(
            "CREATE INDEX IF NOT EXISTS idx_security_logs_domain_created ON security_logs (domain_name, created_at)"
        );
    }

    public function ensureIpAccessRulesTable(): void
    {
        $this->queryD1(
            "CREATE TABLE IF NOT EXISTS ip_access_rules (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                domain_name      TEXT    NOT NULL,
                ip_or_cidr       TEXT    NOT NULL,
                action           TEXT    NOT NULL DEFAULT 'block',
                note             TEXT,
                created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $this->queryD1(
            "CREATE INDEX IF NOT EXISTS idx_ip_rules_domain ON ip_access_rules (domain_name)"
        );
    }

    public function ensureCustomFirewallRulesTable(): void
    {
        $this->queryD1(
            "CREATE TABLE IF NOT EXISTS custom_firewall_rules (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                domain_name      TEXT    NOT NULL,
                description      TEXT,
                action           TEXT    NOT NULL,
                expression_json  TEXT    NOT NULL,
                paused           INTEGER DEFAULT 0,
                created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $this->queryD1(
            "CREATE INDEX IF NOT EXISTS idx_fw_rules_domain ON custom_firewall_rules (domain_name)"
        );
    }

    public function ensureSensitivePathsTable(): void
    {
        
        $this->queryD1(
            "CREATE TABLE IF NOT EXISTS sensitive_paths (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                domain_name      TEXT    NOT NULL,
                path_pattern     TEXT    NOT NULL,
                match_type       TEXT    NOT NULL,
                action           TEXT    NOT NULL,
                created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $this->queryD1(
            "CREATE INDEX IF NOT EXISTS idx_sensitive_paths_domain ON sensitive_paths (domain_name)"
        );
    }

    public function purgeSensitivePathsCache(string $domainName = ''): array
    {
        // Since rules can be "global" or domain specific, we must purge them all if none specified
        // It's easier to just use Wrangler KV API or simply just let them expire...
        // But for 0ms we need to delete. For simplicity, we'll try to delete the exact domain + global.
        if ($domainName === 'global' || $domainName === '') {
             $this->runWrangler('kv:key delete --binding=SESSION_KV "cfr:sensitive_paths:global"');
             // Purge all domains might be complex via Wrangler bulk, so we leave it to TTL if global changes
        } else {
             $this->runWrangler(sprintf('kv:key delete --binding=SESSION_KV "cfr:sensitive_paths:%s"', strtolower(trim($domainName))));
             $this->runWrangler('kv:key delete --binding=SESSION_KV "cfr:sensitive_paths:global"');
        }
        return ['ok' => true];
    }

    public function listSensitivePaths(): array
    {
        $sql = "SELECT * FROM sensitive_paths ORDER BY action ASC, id DESC";
        $result = $this->queryD1($sql);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load sensitive paths.', 'paths' => []];
        }

        $rows = $this->parseWranglerJson($result['output'])[0]['results'] ?? [];
        return ['ok' => true, 'error' => null, 'paths' => $rows];
    }

    public function createSensitivePath(string $domainName, string $pathPattern, string $matchType, string $action, bool $autoPurge = true): array
    {
        $sql = sprintf(
            "INSERT INTO sensitive_paths (domain_name, path_pattern, match_type, action) VALUES ('%s', '%s', '%s', '%s')",
            str_replace("'", "''", trim($domainName)),
            str_replace("'", "''", trim($pathPattern)),
            str_replace("'", "''", trim($matchType)),
            str_replace("'", "''", trim($action))
        );
        $result = $this->queryD1($sql);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to create sensitive path.'];
        }

        if ($autoPurge) {
            $this->purgeSensitivePathsCache($domainName);
        }
        return ['ok' => true, 'error' => null];
    }

    public function deleteSensitivePath(int $id): array
    {
        $sql = sprintf("DELETE FROM sensitive_paths WHERE id = %d", $id);
        $result = $this->queryD1($sql);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to delete sensitive path.'];
        }

        $this->purgeSensitivePathsCache();
        return ['ok' => true, 'error' => null];
    }

    public function deleteBulkSensitivePaths(array $pathIds): array
    {
        if (empty($pathIds)) {
            return ['ok' => true, 'error' => null];
        }

        $safeIds = array_map('intval', $pathIds);
        $inClause = implode(',', $safeIds);
        
        $sql = sprintf("DELETE FROM sensitive_paths WHERE id IN (%s)", $inClause);

        $result = $this->queryD1($sql);
        if ($result['ok']) {
            $this->purgeSensitivePathsCache();
            return ['ok' => true, 'error' => null];
        }
        return ['ok' => false, 'error' => $result['error'] ?: 'Failed to delete selected sensitive paths.'];
    }

    public function getDomainConfig(string $domainName): array
    {
        $sql = sprintf(
            "SELECT domain_name, zone_id, status, force_captcha, turnstile_sitekey, turnstile_secret, created_at
             FROM domain_configs
             WHERE domain_name = '%s'
             LIMIT 1",
            str_replace("'", "''", strtolower(trim($domainName)))
        );
        $result = $this->queryD1($sql);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load domain config.', 'domain' => null];
        }

        $rows = $this->parseWranglerJson($result['output'])[0]['results'] ?? [];
        $row = is_array($rows[0] ?? null) ? $rows[0] : null;
        if (!$row) {
            return ['ok' => false, 'error' => 'Domain not found in configuration.', 'domain' => null];
        }

        return ['ok' => true, 'error' => null, 'domain' => $row];
    }

    public function listZoneWorkerRoutes(string $zoneId): array
    {
        $zone = trim($zoneId);
        if ($zone === '') {
            return ['ok' => false, 'error' => 'Zone ID is empty.', 'routes' => []];
        }

        $routes = [];
        $page = 1;
        while (true) {
            $list = $this->cloudflareRequest('GET', '/zones/'.$zone.'/workers/routes', [
                'page' => $page,
                'per_page' => 100,
            ]);
            if (!$list['ok']) {
                return ['ok' => false, 'error' => $list['error'], 'routes' => []];
            }

            $pageRoutes = is_array($list['result']) ? $list['result'] : [];
            $routes = array_merge($routes, $pageRoutes);
            if (count($pageRoutes) < 100 || $page > 20) {
                break;
            }
            $page++;
        }

        return ['ok' => true, 'error' => null, 'routes' => $routes];
    }

    public function purgeCustomFirewallRulesCache(string $domainName): array
    {
        $cacheKey = 'cfr:' . strtolower(trim($domainName));
        $cmd = 'kv:key delete --binding=SESSION_KV ' . escapeshellarg($cacheKey);
        return $this->runWrangler($cmd);
    }

    public function getCustomFirewallRules(string $domainName): array
    {
        $sql = sprintf(
            "SELECT * FROM custom_firewall_rules WHERE domain_name = '%s' ORDER BY id DESC",
            str_replace("'", "''", strtolower(trim($domainName)))
        );
        $result = $this->queryD1($sql);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load firewall rules.', 'rules' => []];
        }

        $rows = $this->parseWranglerJson($result['output'])[0]['results'] ?? [];
        return ['ok' => true, 'error' => null, 'rules' => $rows];
    }

    public function listPaginatedCustomFirewallRules(int $limit = 20, int $offset = 0): array
    {
        $sql = sprintf("SELECT * FROM custom_firewall_rules ORDER BY domain_name ASC, id DESC LIMIT %d OFFSET %d", $limit, $offset);
        $result = $this->queryD1($sql);
        
        $countSql = "SELECT COUNT(*) as total FROM custom_firewall_rules";
        $countResult = $this->queryD1($countSql);
        
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load global firewall rules.', 'rules' => [], 'total' => 0];
        }

        $rows = $this->parseWranglerJson($result['output'])[0]['results'] ?? [];
        $total = 0;
        if ($countResult['ok']) {
            $totalRows = $this->parseWranglerJson($countResult['output'])[0]['results'] ?? [];
            $total = $totalRows[0]['total'] ?? 0;
        }

        return ['ok' => true, 'error' => null, 'rules' => $rows, 'total' => $total];
    }

    public function listAllCustomFirewallRules(): array
    {
        return $this->listPaginatedCustomFirewallRules(1000, 0);
    }

    public function createCustomFirewallRule(
        string $domainName,
        string $description,
        string $action,
        string $expressionJson,
        bool $paused,
        ?int $expiresAt = null
    ): array {
        $expiresAtStr = $expiresAt !== null ? (string)$expiresAt : 'NULL';
        $sql = sprintf(
            "INSERT INTO custom_firewall_rules (domain_name, description, action, expression_json, paused, expires_at) VALUES ('%s', '%s', '%s', '%s', %d, %s)",
            str_replace("'", "''", strtolower(trim($domainName))),
            str_replace("'", "''", trim($description)),
            str_replace("'", "''", trim($action)),
            str_replace("'", "''", trim($expressionJson)),
            $paused ? 1 : 0,
            $expiresAtStr
        );
        $result = $this->queryD1($sql);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to create firewall rule.'];
        }

        $this->purgeCustomFirewallRulesCache($domainName);
        return ['ok' => true, 'error' => null];
    }

    public function getCustomFirewallRuleById(string $domainName, int $ruleId): array
    {
        $sql = sprintf(
            "SELECT * FROM custom_firewall_rules WHERE domain_name = '%s' AND id = %d LIMIT 1",
            str_replace("'", "''", strtolower(trim($domainName))),
            $ruleId
        );
        $result = $this->queryD1($sql);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load firewall rule.', 'rule' => null];
        }

        $rows = $this->parseWranglerJson($result['output'])[0]['results'] ?? [];
        $row = is_array($rows[0] ?? null) ? $rows[0] : null;
        if (!$row) {
            return ['ok' => false, 'error' => 'Firewall Rule not found.', 'rule' => null];
        }

        return ['ok' => true, 'error' => null, 'rule' => $row];
    }

    /**
     * Fetch a single firewall rule by ID without domain constraint (for bulk operations).
     */
    public function getCustomFirewallRuleByIdGlobal(int $ruleId): array
    {
        $sql = sprintf(
            "SELECT * FROM custom_firewall_rules WHERE id = %d LIMIT 1",
            $ruleId
        );
        $result = $this->queryD1($sql);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load firewall rule.', 'rule' => null];
        }

        $rows = $this->parseWranglerJson($result['output'])[0]['results'] ?? [];
        $row = is_array($rows[0] ?? null) ? $rows[0] : null;
        if (!$row) {
            return ['ok' => false, 'error' => 'Firewall Rule not found.', 'rule' => null];
        }

        return ['ok' => true, 'error' => null, 'rule' => $row];
    }

    public function updateCustomFirewallRule(
        string $domainName,
        int $ruleId,
        string $description,
        string $action,
        string $expressionJson,
        bool $paused,
        ?int $expiresAt = null
    ): array {
        $expiresAtStr = $expiresAt !== null ? (string)$expiresAt : 'NULL';
        $sql = sprintf(
            "UPDATE custom_firewall_rules SET description = '%s', action = '%s', expression_json = '%s', paused = %d, expires_at = %s WHERE domain_name = '%s' AND id = %d",
            str_replace("'", "''", trim($description)),
            str_replace("'", "''", trim($action)),
            str_replace("'", "''", trim($expressionJson)),
            $paused ? 1 : 0,
            $expiresAtStr,
            str_replace("'", "''", strtolower(trim($domainName))),
            $ruleId
        );
        $result = $this->queryD1($sql);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to update firewall rule.'];
        }

        $this->purgeCustomFirewallRulesCache($domainName);
        return ['ok' => true, 'error' => null];
    }

    public function deleteExpiredCustomFirewallRules(): array
    {
        // Executes a silent Cleanup on the database. 
        // We do NOT need to purge KV cache here because the Worker already naturally ignores expired timestamps, 
        // so removing them physically changes nothing about the Edge execution sequence! Fast and lightweight.
        $sql = "DELETE FROM custom_firewall_rules WHERE expires_at IS NOT NULL AND expires_at < " . time();
        return $this->queryD1($sql);
    }

    public function toggleCustomFirewallRule(string $domainName, int $ruleId, bool $paused): array
    {
        $sql = sprintf(
            "UPDATE custom_firewall_rules SET paused = %d WHERE domain_name = '%s' AND id = %d",
            $paused ? 1 : 0,
            str_replace("'", "''", strtolower(trim($domainName))),
            $ruleId
        );
        $result = $this->queryD1($sql);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to update firewall rule.'];
        }

        $this->purgeCustomFirewallRulesCache($domainName);
        $this->purgeCustomFirewallRulesCache($domainName);
        return ['ok' => true, 'error' => null];
    }

    public function deleteCustomFirewallRule(string $domainName, int $ruleId): array
    {
        $domainSanitized = str_replace("'", "''", strtolower(trim($domainName)));
        $sql = sprintf("DELETE FROM custom_firewall_rules WHERE id = %d AND domain_name = '%s'", $ruleId, $domainSanitized);

        $result = $this->queryD1($sql);
        if ($result['ok']) {
            $this->purgeCustomFirewallRulesCache($domainName);
            return ['ok' => true, 'error' => null];
        }
        return ['ok' => false, 'error' => $result['error'] ?: 'Failed to delete custom firewall rule.'];
    }

    public function deleteBulkCustomFirewallRules(array $ruleIds): array
    {
        if (empty($ruleIds)) {
            return ['ok' => true, 'error' => null];
        }

        // Validate IDs are integers to prevent SQL injection
        $safeIds = array_map('intval', $ruleIds);
        $inClause = implode(',', $safeIds);
        
        // Fetch domains first to eagerly purge their caches
        $fetchSql = sprintf("SELECT DISTINCT domain_name FROM custom_firewall_rules WHERE id IN (%s)", $inClause);
        $fetchResult = $this->queryD1($fetchSql);
        $domainsToPurge = [];
        if ($fetchResult['ok']) {
            $rows = $this->parseWranglerJson($fetchResult['output'])[0]['results'] ?? [];
            foreach ($rows as $row) {
                if (!empty($row['domain_name'])) {
                    $domainsToPurge[] = $row['domain_name'];
                }
            }
        }

        $sql = sprintf("DELETE FROM custom_firewall_rules WHERE id IN (%s)", $inClause);

        $result = $this->queryD1($sql);
        if ($result['ok']) {
            foreach ($domainsToPurge as $domainName) {
                $this->purgeCustomFirewallRulesCache($domainName);
            }
            return ['ok' => true, 'error' => null];
        }
        return ['ok' => false, 'error' => $result['error'] ?: 'Failed to delete selected rules.'];
    }

    public function listDomains(): array
    {
        $result = $this->queryD1(
            "SELECT domain_name, zone_id, status, force_captcha, security_mode, created_at FROM domain_configs ORDER BY created_at DESC"
        );
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load domains.', 'domains' => []];
        }

        $rows = $this->parseWranglerJson($result['output'])[0]['results'] ?? [];
        return ['ok' => true, 'error' => null, 'domains' => $rows];
    }

    public function listIpAccessRules(string $domainName): array
    {
        $sql = sprintf(
            "SELECT * FROM ip_access_rules WHERE domain_name = '%s' ORDER BY id DESC",
            str_replace("'", "''", strtolower(trim($domainName)))
        );
        $result = $this->queryD1($sql);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load IP rules.', 'rules' => []];
        }

        $rows = $this->parseWranglerJson($result['output'])[0]['results'] ?? [];
        return ['ok' => true, 'error' => null, 'rules' => $rows];
    }

    public function listAllIpAccessRules(): array
    {
        $sql = "SELECT * FROM ip_access_rules ORDER BY id DESC";
        $result = $this->queryD1($sql);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load IP rules.', 'rules' => []];
        }

        $rows = $this->parseWranglerJson($result['output'])[0]['results'] ?? [];
        return ['ok' => true, 'error' => null, 'rules' => $rows];
    }

    public function purgeIpRulesCache(string $domainName): array
    {
        $cacheKey = 'ipr:' . strtolower(trim($domainName));
        $cmd = 'kv:key delete --binding=SESSION_KV ' . escapeshellarg($cacheKey);
        return $this->runWrangler($cmd);
    }

    public function createIpAccessRule(string $domainName, string $ipOrCidr, string $action, ?string $note): array
    {
        $sql = sprintf(
            "INSERT INTO ip_access_rules (domain_name, ip_or_cidr, action, note) VALUES ('%s', '%s', '%s', '%s')",
            str_replace("'", "''", strtolower(trim($domainName))),
            str_replace("'", "''", trim($ipOrCidr)),
            str_replace("'", "''", trim($action)),
            str_replace("'", "''", trim($note ?? ''))
        );
        $result = $this->queryD1($sql);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to create IP rule.'];
        }

        $this->purgeIpRulesCache($domainName);
        return ['ok' => true, 'error' => null];
    }

    public function getIpAccessRuleById(string $domainName, int $ruleId): array
    {
        $sql = sprintf(
            "SELECT * FROM ip_access_rules WHERE domain_name = '%s' AND id = %d LIMIT 1",
            str_replace("'", "''", strtolower(trim($domainName))),
            $ruleId
        );
        $result = $this->queryD1($sql);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load IP rule.', 'rule' => null];
        }

        $rows = $this->parseWranglerJson($result['output'])[0]['results'] ?? [];
        $row = is_array($rows[0] ?? null) ? $rows[0] : null;
        if (!$row) {
            return ['ok' => false, 'error' => 'IP Rule not found.', 'rule' => null];
        }

        return ['ok' => true, 'error' => null, 'rule' => $row];
    }

    public function updateIpAccessRule(string $domainName, int $ruleId, string $ipOrCidr, string $action, ?string $note): array
    {
        $sql = sprintf(
            "UPDATE ip_access_rules SET ip_or_cidr = '%s', action = '%s', note = '%s' WHERE domain_name = '%s' AND id = %d",
            str_replace("'", "''", trim($ipOrCidr)),
            str_replace("'", "''", trim($action)),
            str_replace("'", "''", trim($note ?? '')),
            str_replace("'", "''", strtolower(trim($domainName))),
            $ruleId
        );
        $result = $this->queryD1($sql);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to update IP rule.'];
        }

        $this->purgeIpRulesCache($domainName);
        return ['ok' => true, 'error' => null];
    }

    public function deleteIpAccessRule(string $domainName, int $ruleId): array
    {
        $sql = sprintf(
            "DELETE FROM ip_access_rules WHERE domain_name = '%s' AND id = %d",
            str_replace("'", "''", strtolower(trim($domainName))),
            $ruleId
        );
        $result = $this->queryD1($sql);
        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to delete IP rule.'];
        }

        $this->purgeIpRulesCache($domainName);
        return ['ok' => true, 'error' => null];
    }

    public function runInProject(string $command, int $timeout = 60): array
    {
        if (!$this->isProcOpenAvailable()) {
            return [
                'ok' => false,
                'exit_code' => 127,
                'output' => '',
                'error' => 'PHP function proc_open is disabled on this server. Worker runtime commands cannot run from dashboard.',
                'command' => $command,
            ];
        }

        $full = 'cd '.escapeshellarg($this->projectRoot()).' && '.$this->sanitizeCommandForNode($command);
        try {
            $process = Process::fromShellCommandline($full);
            $process->setTimeout($timeout);
            $process->run();
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'exit_code' => 1,
                'output' => '',
                'error' => $this->compactErrorMessage($e->getMessage()),
                'command' => $command,
            ];
        }

        return [
            'ok' => $process->isSuccessful(),
            'exit_code' => $process->getExitCode(),
            'output' => trim($process->getOutput()),
            'error' => trim($process->getErrorOutput()),
            'command' => $command,
        ];
    }

    private function isProcOpenAvailable(): bool
    {
        if ($this->procOpenAvailable !== null) {
            return $this->procOpenAvailable;
        }

        if (!function_exists('proc_open')) {
            $this->procOpenAvailable = false;
            return false;
        }

        $disabled = (string) ini_get('disable_functions');
        $disabledFunctions = array_map(
            static fn (string $item): string => trim(strtolower($item)),
            explode(',', strtolower($disabled))
        );

        $this->procOpenAvailable = !in_array('proc_open', $disabledFunctions, true);
        return $this->procOpenAvailable;
    }

    public function runWrangler(string $args, int $timeout = 60): array
    {
        return $this->runInProject($this->wranglerBin().' '.$args, $timeout);
    }

    public function queryD1(string $sql, int $timeout = 90): array
    {
        $cmd = sprintf(
            '%s d1 execute EDGE_SHIELD_DB --remote --command %s',
            $this->wranglerBin(),
            escapeshellarg($sql)
        );
        $effectiveTimeout = max(10, min(300, $timeout));
        return $this->runInProject($cmd, $effectiveTimeout);
    }

    public function parseWranglerJson(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $start = strpos($raw, '[');
        if ($start === false) {
            return [];
        }

        $json = substr($raw, $start);
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        return $decoded;
    }

    public function allowIpViaWorkerAdmin(
        string $domain,
        string $ip,
        int $ttlHours = 24,
        string $reason = 'dashboard manual allow from logs'
    ): array {
        $host = strtolower(trim($domain));
        $host = preg_replace('#^https?://#i', '', $host) ?? $host;
        $host = explode('/', $host, 2)[0] ?? $host;
        $host = trim($host);
        if ($host === '') {
            return ['ok' => false, 'error' => 'Domain is required to call worker admin endpoint.'];
        }

        $token = (string) ($this->getDashboardSetting('es_admin_token') ?? '');
        if (trim($token) === '') {
            return ['ok' => false, 'error' => 'ES Admin Token is missing in settings.'];
        }

        $hours = max(1, min(24 * 30, (int) $ttlHours));
        $url = 'https://'.$host.'/es-admin/ip/allow';

        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->withHeaders([
                    'X-ES-Admin-Token' => $token,
                ])
                ->post($url, [
                    'ip' => $ip,
                    'ttlHours' => $hours,
                    'reason' => $reason,
                ]);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'Worker admin request failed: '.$e->getMessage(),
            ];
        }

        $payload = $response->json();
        if (!$response->ok()) {
            $message = null;
            if (is_array($payload)) {
                $message = $payload['error']['message'] ?? $payload['message'] ?? null;
            }
            return [
                'ok' => false,
                'error' => $message
                    ? 'Worker admin HTTP error: '.$response->status().' ('.$message.')'
                    : 'Worker admin HTTP error: '.$response->status(),
            ];
        }

        if (!is_array($payload) || (($payload['success'] ?? false) !== true)) {
            $message = is_array($payload)
                ? (string) ($payload['error']['message'] ?? $payload['message'] ?? 'Worker admin reported failure.')
                : 'Unexpected worker admin response.';
            return ['ok' => false, 'error' => $message];
        }

        return [
            'ok' => true,
            'error' => null,
            'result' => $payload,
        ];
    }

    public function getIpAdminStatusViaWorkerAdmin(string $domain, string $ip): array
    {
        $host = strtolower(trim($domain));
        $host = preg_replace('#^https?://#i', '', $host) ?? $host;
        $host = explode('/', $host, 2)[0] ?? $host;
        $host = trim($host);
        if ($host === '') {
            return ['ok' => false, 'error' => 'Domain is required to call worker admin endpoint.'];
        }
        if (trim($ip) === '') {
            return ['ok' => false, 'error' => 'IP is required.'];
        }

        $token = (string) ($this->getDashboardSetting('es_admin_token') ?? '');
        if (trim($token) === '') {
            return ['ok' => false, 'error' => 'ES Admin Token is missing in settings.'];
        }

        $url = 'https://'.$host.'/es-admin/ip/status?ip='.rawurlencode(trim($ip));
        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->withHeaders([
                    'X-ES-Admin-Token' => $token,
                ])
                ->get($url);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'Worker admin request failed: '.$e->getMessage(),
            ];
        }

        $payload = $response->json();
        if (!$response->ok()) {
            $message = null;
            if (is_array($payload)) {
                $message = $payload['error']['message'] ?? $payload['message'] ?? null;
            }
            return [
                'ok' => false,
                'error' => $message
                    ? 'Worker admin HTTP error: '.$response->status().' ('.$message.')'
                    : 'Worker admin HTTP error: '.$response->status(),
            ];
        }

        if (!is_array($payload) || (($payload['success'] ?? false) !== true)) {
            $message = is_array($payload)
                ? (string) ($payload['error']['message'] ?? $payload['message'] ?? 'Worker admin reported failure.')
                : 'Unexpected worker admin response.';
            return ['ok' => false, 'error' => $message];
        }

        return [
            'ok' => true,
            'error' => null,
            'status' => [
                'ip' => (string) ($payload['ip'] ?? $ip),
                'banned' => (bool) ($payload['banned'] ?? false),
                'allowed' => (bool) ($payload['allowed'] ?? false),
            ],
        ];
    }

    public function blockIpViaWorkerAdmin(
        string $domain,
        string $ip,
        int $ttlHours = 24,
        string $reason = 'dashboard manual block from logs'
    ): array {
        $host = strtolower(trim($domain));
        $host = preg_replace('#^https?://#i', '', $host) ?? $host;
        $host = explode('/', $host, 2)[0] ?? $host;
        $host = trim($host);
        if ($host === '') {
            return ['ok' => false, 'error' => 'Domain is required to call worker admin endpoint.'];
        }

        $token = (string) ($this->getDashboardSetting('es_admin_token') ?? '');
        if (trim($token) === '') {
            return ['ok' => false, 'error' => 'ES Admin Token is missing in settings.'];
        }

        $hours = max(1, min(24 * 30, (int) $ttlHours));
        $url = 'https://'.$host.'/es-admin/ip/ban';

        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->withHeaders([
                    'X-ES-Admin-Token' => $token,
                ])
                ->post($url, [
                    'ip' => $ip,
                    'ttlHours' => $hours,
                    'reason' => $reason,
                ]);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'Worker admin request failed: '.$e->getMessage(),
            ];
        }

        $payload = $response->json();
        if (!$response->ok()) {
            $message = null;
            if (is_array($payload)) {
                $message = $payload['error']['message'] ?? $payload['message'] ?? null;
            }
            return [
                'ok' => false,
                'error' => $message
                    ? 'Worker admin HTTP error: '.$response->status().' ('.$message.')'
                    : 'Worker admin HTTP error: '.$response->status(),
            ];
        }

        if (!is_array($payload) || (($payload['success'] ?? false) !== true)) {
            $message = is_array($payload)
                ? (string) ($payload['error']['message'] ?? $payload['message'] ?? 'Worker admin reported failure.')
                : 'Unexpected worker admin response.';
            return ['ok' => false, 'error' => $message];
        }

        return [
            'ok' => true,
            'error' => null,
            'result' => $payload,
        ];
    }

    /**
     * Revoke an admin allow-list entry from KV via the worker admin API.
     */
    public function revokeAllowIpViaWorkerAdmin(string $domain, string $ip): array
    {
        return $this->workerAdminPost($domain, '/es-admin/ip/revoke-allow', ['ip' => $ip]);
    }

    /**
     * Revoke an admin ban entry from KV via the worker admin API.
     */
    public function unbanIpViaWorkerAdmin(string $domain, string $ip): array
    {
        return $this->workerAdminPost($domain, '/es-admin/ip/unban', ['ip' => $ip]);
    }

    /**
     * Given a firewall rule's expression_json and action, sync KV by revoking the
     * corresponding admin allow/ban entry. Best-effort: failures are silently ignored.
     */
    public function syncKvForFirewallRuleAction(string $domain, string $expressionJson, string $action): void
    {
        $ip = $this->extractIpFromExpression($expressionJson);
        if ($ip === null || trim($domain) === '') {
            return;
        }

        if ($action === 'allow') {
            $this->revokeAllowIpViaWorkerAdmin($domain, $ip);
        } elseif ($action === 'block') {
            $this->unbanIpViaWorkerAdmin($domain, $ip);
        }
    }

    /**
     * Extract a single IP from a firewall rule expression_json (ip.src eq "x.x.x.x").
     */
    private function extractIpFromExpression(string $expressionJson): ?string
    {
        $decoded = json_decode($expressionJson, true);
        if (!is_array($decoded)) {
            return null;
        }

        $field = trim((string) ($decoded['field'] ?? ''));
        $operator = trim((string) ($decoded['operator'] ?? ''));
        $value = trim((string) ($decoded['value'] ?? ''));

        if ($field === 'ip.src' && $operator === 'eq' && $value !== '' && filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }

        return null;
    }

    /**
     * Generic helper to POST to a worker admin endpoint.
     */
    private function workerAdminPost(string $domain, string $path, array $payload): array
    {
        $host = strtolower(trim($domain));
        $host = preg_replace('#^https?://#i', '', $host) ?? $host;
        $host = explode('/', $host, 2)[0] ?? $host;
        $host = trim($host);
        if ($host === '') {
            return ['ok' => false, 'error' => 'Domain is required to call worker admin endpoint.'];
        }

        $token = (string) ($this->getDashboardSetting('es_admin_token') ?? '');
        if (trim($token) === '') {
            return ['ok' => false, 'error' => 'ES Admin Token is missing in settings.'];
        }

        $url = 'https://'.$host.$path;

        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->withHeaders(['X-ES-Admin-Token' => $token])
                ->post($url, $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Worker admin request failed: '.$e->getMessage()];
        }

        $data = $response->json();
        if (!$response->ok() || !is_array($data) || (($data['success'] ?? false) !== true)) {
            $message = is_array($data) ? (string) ($data['error']['message'] ?? $data['message'] ?? 'Worker admin reported failure.') : 'Unexpected response.';
            return ['ok' => false, 'error' => $message];
        }

        return ['ok' => true, 'error' => null, 'result' => $data];
    }

    private function normalizeDomain(string $domainName): string
    {
        $domain = strtolower(trim($domainName));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = explode('/', $domain, 2)[0] ?? $domain;
        return trim($domain);
    }

    private function turnstileAllowedDomains(string $domain): array
    {
        if ($domain === '') {
            return [];
        }

        if (str_starts_with($domain, 'www.')) {
            $apex = substr($domain, 4);
            return array_values(array_unique([$domain, $apex]));
        }

        return array_values(array_unique([$domain, 'www.'.$domain]));
    }

    private function resolveZoneAccountId(string $zoneId): array
    {
        $zone = trim($zoneId);
        if ($zone === '') {
            return ['ok' => false, 'error' => 'Zone ID is empty.', 'account_id' => null];
        }

        $zoneResp = $this->cloudflareRequest('GET', '/zones/'.$zone);
        if (!$zoneResp['ok']) {
            return ['ok' => false, 'error' => $zoneResp['error'], 'account_id' => null];
        }

        $zoneRow = is_array($zoneResp['result']) ? $zoneResp['result'] : [];
        $accountId = is_string($zoneRow['account']['id'] ?? null) ? trim($zoneRow['account']['id']) : '';
        if ($accountId === '') {
            return ['ok' => false, 'error' => 'Unable to resolve Cloudflare account for the zone.', 'account_id' => null];
        }

        return ['ok' => true, 'error' => null, 'account_id' => $accountId];
    }

    private function deleteTurnstileWidget(string $zoneId, string $siteKey): array
    {
        $key = trim($siteKey);
        if ($key === '') {
            return ['ok' => false, 'error' => 'Turnstile site key is empty.'];
        }

        $account = $this->resolveZoneAccountId($zoneId);
        if (!$account['ok']) {
            return ['ok' => false, 'error' => $account['error']];
        }

        $delete = $this->cloudflareRequest(
            'DELETE',
            '/accounts/'.$account['account_id'].'/challenges/widgets/'.$key
        );
        if (!$delete['ok']) {
            return ['ok' => false, 'error' => $delete['error']];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Creates or updates a SINGLE consolidated Zone Cache Rule (via Rulesets API) to bypass edge cache
     * if the visitor does not have an Edge Shield session cookie (es_session).
     * This forces attackers to hit the Worker's flood protection instead of spinning
     * on cached pages for click fraud.
     *
     * It consolidates ALL active domains for this zone into one rule:
     * (http.host in {"d1.com" "d2.com"} and not http.cookie contains "es_session=")
     * This avoids hitting Cloudflare Free Plan limits (max 10 rules per zone).
     */
    public function ensureCacheRuleForEdgeShield(string $zoneId, string $triggeringDomain): array
    {
        $zone = trim($zoneId);
        if ($zone === '') {
            return ['ok' => false, 'error' => 'Zone ID is empty.'];
        }

        // Fetch all active domains for this zone from our DB to consolidate them into ONE rule
        $sql = sprintf(
            "SELECT domain_name FROM domain_configs WHERE zone_id = '%s' AND status = 'active'",
            str_replace("'", "''", $zone)
        );
        $result = $this->queryD1($sql);
        
        $domains = [];
        if ($result['ok']) {
            $rows = $this->parseWranglerJson($result['output'])[0]['results'] ?? [];
            foreach ($rows as $row) {
                if (!empty($row['domain_name'])) {
                    $domains[] = strtolower(trim($row['domain_name']));
                    // Add www version if strictly needed, though usually http.host matches exactly what's requested
                    if (!str_starts_with($row['domain_name'], 'www.')) {
                        $domains[] = 'www.' . strtolower(trim($row['domain_name']));
                    }
                }
            }
        }
        
        // Always include the triggering domain just in case D1 replication is delayed
        $triggeringDomain = strtolower(trim($triggeringDomain));
        if ($triggeringDomain !== '') {
            $domains[] = $triggeringDomain;
            if (!str_starts_with($triggeringDomain, 'www.')) {
                $domains[] = 'www.' . $triggeringDomain;
            }
        }
        
        $domains = array_values(array_unique($domains));
        
        if (empty($domains)) {
            // Nothing to protect
            return ['ok' => true, 'action' => 'skipped'];
        }

        $ruleDescription = 'Edge Shield Cache Protection - Bypass Cache without Session';
        
        // Build the consolidated expression: (http.host in {"domain1.com" "domain2.com"} and not http.cookie contains "es_session=")
        $hostList = implode(' ', array_map(fn($d) => '"' . $d . '"', $domains));
        $expression = sprintf('(http.host in {%s} and not http.cookie contains "es_session=")', $hostList);

        $newRule = [
            'description' => $ruleDescription,
            'expression' => $expression,
            'action' => 'set_cache_settings',
            'action_parameters' => [
                'cache' => false
            ]
        ];

        // 1. Get the http_request_cache_settings ruleset for this zone
        $lookup = $this->cloudflareRequest('GET', '/zones/'.$zone.'/rulesets', ['phase' => 'http_request_cache_settings']);
        
        if (!$lookup['ok']) {
            return ['ok' => false, 'error' => 'Failed to lookup Cache Rulesets phase: ' . $lookup['error']];
        }

        $rulesets = $lookup['result'] ?? [];
        $targetRulesetId = null;
        
        foreach ($rulesets as $rs) {
            if (is_array($rs) && ($rs['phase'] ?? '') === 'http_request_cache_settings' && ($rs['kind'] ?? '') === 'zone') {
                $targetRulesetId = $rs['id'] ?? null;
                break;
            }
        }

        // 2. If it doesn't exist, create it with our rule
        if (!$targetRulesetId) {
            $createPayload = [
                'name' => 'default',
                'description' => 'Zone Cache Rules created by Edge Shield',
                'kind' => 'zone',
                'phase' => 'http_request_cache_settings',
                'rules' => [$newRule]
            ];
            
            $create = $this->cloudflareRequest('POST', '/zones/'.$zone.'/rulesets', [], $createPayload);
            if (!$create['ok']) {
                return ['ok' => false, 'error' => 'Failed to create Cache Ruleset: ' . $create['error']];
            }
            return ['ok' => true, 'error' => null, 'action' => 'created'];
        }

        // 3. If ruleset exists, fetch it to check existing rules
        $rulesetDetails = $this->cloudflareRequest('GET', '/zones/'.$zone.'/rulesets/'.$targetRulesetId);
        if (!$rulesetDetails['ok']) {
            return ['ok' => false, 'error' => 'Failed to fetch existing Cache Ruleset details: ' . $rulesetDetails['error']];
        }
        
        $rules = $rulesetDetails['result']['rules'] ?? [];
        $existingRuleId = null;
        $isExactlySame = false;

        foreach ($rules as $rule) {
            if (($rule['description'] ?? '') === $ruleDescription) {
                $existingRuleId = $rule['id'] ?? null;
                if (($rule['expression'] ?? '') === $expression && ($rule['action'] ?? '') === 'set_cache_settings' && ($rule['action_parameters']['cache'] ?? null) === false) {
                    $isExactlySame = true;
                }
                break;
            }
        }

        if ($isExactlySame && $existingRuleId) {
            return ['ok' => true, 'error' => null, 'action' => 'already_exists'];
        }

        // 4. Update or Add the rule to the ruleset
        if ($existingRuleId) {
            // Update existing rule
            $update = $this->cloudflareRequest('PATCH', '/zones/'.$zone.'/rulesets/'.$targetRulesetId.'/rules/'.$existingRuleId, [], $newRule);
            if (!$update['ok']) {
                return ['ok' => false, 'error' => 'Failed to update Cache Rule: ' . $update['error']];
            }
            return ['ok' => true, 'error' => null, 'action' => 'updated'];
        } else {
            // Append new rule
            // Cloudflare API doesn't support appending nicely to rules arrays via POST to /rulesets.
            // We use POST /zones/:zone_identifier/rulesets/:ruleset_id/rules to add a new rule.
            $add = $this->cloudflareRequest('POST', '/zones/'.$zone.'/rulesets/'.$targetRulesetId.'/rules', [], $newRule);
            if (!$add['ok']) {
                return ['ok' => false, 'error' => 'Failed to add Cache Rule to existing ruleset: ' . $add['error']];
            }
            return ['ok' => true, 'error' => null, 'action' => 'appended'];
        }
    }

    private function getDashboardSetting(string $key): ?string
    {
        if ($this->settingsCache === null) {
            $settings = DashboardSetting::query()->get();
            foreach ($settings as $setting) {
                if (!$setting instanceof DashboardSetting || !$setting->isSensitiveKey()) {
                    continue;
                }

                $rawValue = (string) $setting->getRawOriginal('value');
                if ($rawValue === '' || str_starts_with($rawValue, 'enc:v1:')) {
                    continue;
                }

                // One-time in-place migration for legacy plaintext secrets.
                $setting->value = $rawValue;
                $setting->save();
            }

            $this->settingsCache = $settings
                ->mapWithKeys(fn (DashboardSetting $setting): array => [$setting->key => $setting->value])
                ->all();
        }

        $normalized = trim((string) ($this->settingsCache[$key] ?? ''));
        return $normalized !== '' ? $normalized : null;
    }

    private function syncWorkerSecret(string $name, string $value): array
    {
        $tmp = tempnam(storage_path('app'), 'edge-secret-');
        if ($tmp === false) {
            return [
                'ok' => false,
                'exit_code' => 1,
                'output' => '',
                'error' => 'Failed to create temporary file for secret sync.',
                'command' => '',
            ];
        }

        try {
            file_put_contents($tmp, $value);
            $cmd = sprintf(
                "%s secret put %s < %s",
                $this->wranglerBin(),
                escapeshellarg($name),
                escapeshellarg($tmp)
            );
            return $this->runInProject($cmd, 120);
        } finally {
            @unlink($tmp);
        }
    }

    public function syncCloudflareFromDashboardSettings(): array
    {
        $secrets = [
            'CF_API_TOKEN' => $this->getDashboardSetting('cf_api_token'),
            'OPENROUTER_API_KEY' => $this->getDashboardSetting('openrouter_api_key'),
            'JWT_SECRET' => $this->getDashboardSetting('jwt_secret'),
            'ES_ADMIN_TOKEN' => $this->getDashboardSetting('es_admin_token'),
        ];

        $logs = [];
        $errors = [];

        if (!is_string($secrets['CF_API_TOKEN']) || trim($secrets['CF_API_TOKEN']) === '') {
            return [
                'ok' => false,
                'logs' => [],
                'errors' => ['CF_API_TOKEN is required in Dashboard settings before sync.'],
                'deploy' => null,
            ];
        }

        foreach ($secrets as $name => $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $result = $this->syncWorkerSecret($name, $value);
            $logs[] = sprintf('secret:%s exit=%s', $name, (string) ($result['exit_code'] ?? 'n/a'));
            if (!$result['ok']) {
                $raw = (string) (($result['error'] ?? '') ?: ($result['output'] ?? 'secret sync failed'));
                $errors[] = sprintf('%s => %s', $name, $this->compactErrorMessage($raw));
            }
        }

        $openrouterModel = $this->getDashboardSetting('openrouter_model')
            ?? 'qwen/qwen3-next-80b-a3b-instruct:free';
        $openrouterFallbacks = $this->getDashboardSetting('openrouter_fallback_models')
            ?? 'openai/gpt-oss-120b:free,nvidia/nemotron-3-super:free';
        $disableWaf = $this->getDashboardSetting('es_disable_waf_autodeploy') ?? 'on';
        $allowUaCompat = $this->getDashboardSetting('es_allow_ua_crawler_allowlist') ?? 'off';
        $adminAllowedIps = $this->getDashboardSetting('es_admin_allowed_ips') ?? '';
        $adminRatePerMin = $this->getDashboardSetting('es_admin_rate_limit_per_min') ?? '60';
        $blockRedirectUrl = $this->getDashboardSetting('es_block_redirect_url') ?? '';

        $deployCmd = $this->wranglerBin().' deploy --keep-vars'
            .' --var '.escapeshellarg('OPENROUTER_MODEL:'.$openrouterModel)
            .' --var '.escapeshellarg('OPENROUTER_FALLBACK_MODELS:'.$openrouterFallbacks)
            .' --var '.escapeshellarg('ES_DISABLE_WAF_AUTODEPLOY:'.$disableWaf)
            .' --var '.escapeshellarg('ES_ALLOW_UA_CRAWLER_ALLOWLIST:'.$allowUaCompat)
            .' --var '.escapeshellarg('ES_ADMIN_ALLOWED_IPS:'.$adminAllowedIps)
            .' --var '.escapeshellarg('ES_ADMIN_RATE_LIMIT_PER_MIN:'.$adminRatePerMin)
            .' --var '.escapeshellarg('ES_BLOCK_REDIRECT_URL:'.$blockRedirectUrl);

        $deploy = $this->runInProject($deployCmd, 240);
        $logs[] = 'deploy-with-vars exit='.(string) ($deploy['exit_code'] ?? 'n/a');
        if (!$deploy['ok']) {
            $raw = (string) (($deploy['error'] ?? '') ?: ($deploy['output'] ?? 'deploy failed'));
            $errors[] = 'deploy => '.$this->compactErrorMessage($raw);
        } else {
            $routeSync = $this->syncAllActiveDomainRoutes();
            if (!$routeSync['ok']) {
                $errors[] = 'route-sync => '.((string) ($routeSync['error'] ?? 'route sync failed'));
            } elseif (!empty($routeSync['synced'])) {
                $logs[] = 'route-sync => '.implode(', ', $routeSync['synced']);
            }
        }

        return [
            'ok' => count($errors) === 0,
            'logs' => $logs,
            'errors' => $errors,
            'deploy' => $deploy,
        ];
    }
}
