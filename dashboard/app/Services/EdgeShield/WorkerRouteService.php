<?php

namespace App\Services\EdgeShield;

class WorkerRouteService
{
    public function __construct(
        private readonly CloudflareApiClient $cloudflare,
        private readonly EdgeShieldConfig $config
    ) {}

    public function ensureWorkerRoute(string $zoneId, string $domainName, ?array $cacheRuleResult = null): array
    {
        $zone = trim($zoneId);
        $domain = $this->normalizeDomain($domainName);
        if ($zone === '' || $domain === '') {
            return ['ok' => false, 'error' => 'Zone ID or domain is empty.'];
        }

        $cacheRuleFailed = is_array($cacheRuleResult) && (($cacheRuleResult['ok'] ?? false) !== true);
        $cacheRuleError = is_array($cacheRuleResult) ? ($cacheRuleResult['error'] ?? null) : null;

        $patterns = $this->routePatternsForDomain($domain);
        $script = $this->config->workerScriptName();
        $routesResult = $this->listRoutesForZone($zone);
        if (! $routesResult['ok']) {
            return ['ok' => false, 'error' => $routesResult['error']];
        }

        $routes = $routesResult['routes'];
        $actions = [];

        foreach ($patterns as $pattern) {
            $matched = $this->findRouteByPattern($routes, $pattern);

            if ($matched && is_string($matched['script'] ?? null) && $matched['script'] === $script) {
                $actions[] = $pattern.':already_synced';

                continue;
            }

            if ($matched && is_string($matched['id'] ?? null)) {
                $update = $this->cloudflare->request(
                    'PUT',
                    '/zones/'.$zone.'/workers/routes/'.$matched['id'],
                    [],
                    ['pattern' => $pattern, 'script' => $script]
                );
                if (! $update['ok']) {
                    return ['ok' => false, 'error' => $update['error']];
                }
                $actions[] = $pattern.':updated';

                continue;
            }

            $create = $this->cloudflare->request(
                'POST',
                '/zones/'.$zone.'/workers/routes',
                [],
                ['pattern' => $pattern, 'script' => $script]
            );
            if (! $create['ok']) {
                if (str_contains(strtolower((string) $create['error']), '409')) {
                    $actions[] = $pattern.':already_exists';

                    continue;
                }

                return ['ok' => false, 'error' => $create['error']];
            }
            $actions[] = $pattern.':created';
        }

        if (is_array($cacheRuleResult) && isset($cacheRuleResult['action'])) {
            $actions[] = 'cache_rule:'.$cacheRuleResult['action'];
        }

        return [
            'ok' => ! $cacheRuleFailed,
            'error' => $cacheRuleFailed ? 'Worker routes synced, but Cache Rule sync failed: '.$cacheRuleError : null,
            'action' => implode(', ', $actions),
        ];
    }

    public function removeWorkerRoutes(string $zoneId, string $domainName): array
    {
        $zone = trim($zoneId);
        $domain = $this->normalizeDomain($domainName);
        if ($zone === '' || $domain === '') {
            return ['ok' => false, 'error' => 'Zone ID or domain is empty.'];
        }

        $patterns = $this->routePatternsForDomain($domain);
        $script = $this->config->workerScriptName();
        $routesResult = $this->listRoutesForZone($zone);
        if (! $routesResult['ok']) {
            return ['ok' => false, 'error' => $routesResult['error']];
        }

        $routes = $routesResult['routes'];
        $actions = [];
        foreach ($patterns as $pattern) {
            $matched = null;
            foreach ($routes as $route) {
                if (! is_array($route)) {
                    continue;
                }
                if (($route['pattern'] ?? null) === $pattern && ($route['script'] ?? null) === $script) {
                    $matched = $route;
                    break;
                }
            }

            if (! $matched || ! is_string($matched['id'] ?? null) || trim((string) $matched['id']) === '') {
                $actions[] = $pattern.':not_found';

                continue;
            }

            $delete = $this->cloudflare->request('DELETE', '/zones/'.$zone.'/workers/routes/'.$matched['id']);
            if (! $delete['ok']) {
                return ['ok' => false, 'error' => $delete['error']];
            }

            $actions[] = $pattern.':deleted';
        }

        return ['ok' => true, 'error' => null, 'action' => implode(', ', $actions)];
    }

    public function listZoneWorkerRoutes(string $zoneId): array
    {
        $zone = trim($zoneId);
        if ($zone === '') {
            return ['ok' => false, 'error' => 'Zone ID is empty.', 'routes' => []];
        }

        return $this->listRoutesForZone($zone);
    }

    private function listRoutesForZone(string $zoneId): array
    {
        $routes = [];
        $page = 1;
        while (true) {
            $list = $this->cloudflare->request('GET', '/zones/'.$zoneId.'/workers/routes', [
                'page' => $page,
                'per_page' => 100,
            ]);
            if (! $list['ok']) {
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

    private function routePatternsForDomain(string $domain): array
    {
        $primaryDomain = $domain;
        $secondaryDomain = str_starts_with($domain, 'www.')
            ? substr($domain, 4)
            : 'www.'.$domain;

        return array_values(array_filter(array_unique([
            $primaryDomain.'/*',
            $secondaryDomain !== '' ? $secondaryDomain.'/*' : null,
        ])));
    }

    private function findRouteByPattern(array $routes, string $pattern): ?array
    {
        foreach ($routes as $route) {
            if (! is_array($route)) {
                continue;
            }
            if (($route['pattern'] ?? null) === $pattern) {
                return $route;
            }
        }

        return null;
    }

    private function normalizeDomain(string $domainName): string
    {
        $domain = strtolower(trim($domainName));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = explode('/', $domain, 2)[0];

        return trim($domain);
    }
}
