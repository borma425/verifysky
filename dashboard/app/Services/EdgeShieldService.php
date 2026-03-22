<?php

namespace App\Services;

use App\Models\DashboardSetting;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

class EdgeShieldService
{
    private const CF_API_BASE = 'https://api.cloudflare.com/client/v4';
    private ?array $settingsCache = null;

    public function projectRoot(): string
    {
        $candidates = [
            base_path('../worker'),
            base_path('../../cloudflare_antibots/worker'),
            base_path('..'),
            dirname(base_path()),
        ];

        $defaultRoot = null;
        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
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

        return [
            'ok' => true,
            'error' => null,
            'domain_name' => $domain,
            'zone_id' => $resolvedZoneId,
            'turnstile_sitekey' => $resolvedSiteKey,
            'turnstile_secret' => $resolvedSecret,
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

        return ['ok' => true, 'error' => null, 'action' => implode(', ', $actions)];
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

    public function listZoneFirewallRules(string $zoneId): array
    {
        $zone = trim($zoneId);
        if ($zone === '') {
            return ['ok' => false, 'error' => 'Zone ID is empty.', 'rules' => []];
        }

        $rules = [];
        $page = 1;
        while (true) {
            $list = $this->cloudflareRequest('GET', '/zones/'.$zone.'/firewall/rules', [
                'page' => $page,
                'per_page' => 100,
            ]);
            if (!$list['ok']) {
                return ['ok' => false, 'error' => $list['error'], 'rules' => []];
            }

            $pageRules = is_array($list['result']) ? $list['result'] : [];
            $rules = array_merge($rules, $pageRules);
            if (count($pageRules) < 100 || $page > 20) {
                break;
            }
            $page++;
        }

        return ['ok' => true, 'error' => null, 'rules' => $rules];
    }

    public function createZoneFirewallRule(
        string $zoneId,
        string $expression,
        string $action,
        ?string $description = null,
        bool $paused = false
    ): array {
        $zone = trim($zoneId);
        $expr = trim($expression);
        if ($zone === '' || $expr === '') {
            return ['ok' => false, 'error' => 'Zone ID or expression is empty.'];
        }

        $payload = [[
            'description' => trim((string) ($description ?? 'Edge Shield rule')),
            'action' => $action,
            'paused' => $paused,
            'filter' => ['expression' => $expr],
        ]];

        $create = $this->cloudflareRequest('POST', '/zones/'.$zone.'/firewall/rules', [], $payload);
        if (!$create['ok']) {
            return ['ok' => false, 'error' => $create['error']];
        }

        $createdRule = is_array($create['result'][0] ?? null) ? $create['result'][0] : null;
        return ['ok' => true, 'error' => null, 'rule' => $createdRule];
    }

    public function setZoneFirewallRulePaused(string $zoneId, string $ruleId, bool $paused): array
    {
        $zone = trim($zoneId);
        $rule = trim($ruleId);
        if ($zone === '' || $rule === '') {
            return ['ok' => false, 'error' => 'Zone ID or rule ID is empty.'];
        }

        $update = $this->cloudflareRequest(
            'PATCH',
            '/zones/'.$zone.'/firewall/rules/'.$rule,
            [],
            ['paused' => $paused]
        );
        if (!$update['ok']) {
            return ['ok' => false, 'error' => $update['error']];
        }

        return ['ok' => true, 'error' => null];
    }

    public function deleteZoneFirewallRule(string $zoneId, string $ruleId): array
    {
        $zone = trim($zoneId);
        $rule = trim($ruleId);
        if ($zone === '' || $rule === '') {
            return ['ok' => false, 'error' => 'Zone ID or rule ID is empty.'];
        }

        $delete = $this->cloudflareRequest('DELETE', '/zones/'.$zone.'/firewall/rules/'.$rule);
        if (!$delete['ok']) {
            return ['ok' => false, 'error' => $delete['error']];
        }

        return ['ok' => true, 'error' => null];
    }

    public function runInProject(string $command, int $timeout = 60): array
    {
        $full = 'cd '.escapeshellarg($this->projectRoot()).' && '.$this->sanitizeCommandForNode($command);
        $process = Process::fromShellCommandline($full);
        $process->setTimeout($timeout);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'exit_code' => $process->getExitCode(),
            'output' => trim($process->getOutput()),
            'error' => trim($process->getErrorOutput()),
            'command' => $command,
        ];
    }

    public function runWrangler(string $args, int $timeout = 60): array
    {
        return $this->runInProject($this->wranglerBin().' '.$args, $timeout);
    }

    public function queryD1(string $sql): array
    {
        $cmd = sprintf(
            '%s d1 execute EDGE_SHIELD_DB --remote --command %s',
            $this->wranglerBin(),
            escapeshellarg($sql)
        );
        return $this->runInProject($cmd, 90);
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

        $deployCmd = $this->wranglerBin().' deploy --keep-vars'
            .' --var '.escapeshellarg('OPENROUTER_MODEL:'.$openrouterModel)
            .' --var '.escapeshellarg('OPENROUTER_FALLBACK_MODELS:'.$openrouterFallbacks)
            .' --var '.escapeshellarg('ES_DISABLE_WAF_AUTODEPLOY:'.$disableWaf)
            .' --var '.escapeshellarg('ES_ALLOW_UA_CRAWLER_ALLOWLIST:'.$allowUaCompat)
            .' --var '.escapeshellarg('ES_ADMIN_ALLOWED_IPS:'.$adminAllowedIps)
            .' --var '.escapeshellarg('ES_ADMIN_RATE_LIMIT_PER_MIN:'.$adminRatePerMin);

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
