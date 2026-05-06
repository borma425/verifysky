<?php

namespace App\Services\EdgeShield\Concerns;

use App\Services\Domains\DnsVerificationService;

trait SaasHostnameLifecycleConcern
{
    public function saasHostnamesForInput(string $domainName, string $apexMode = 'www_redirect'): array
    {
        return app(DnsVerificationService::class)->hostnamesForInput($domainName, $apexMode);
    }

    public function refreshSaasCustomHostname(string $domainName): array
    {
        $domain = $this->normalizeDomain($domainName);
        $zoneId = $this->config->saasZoneId();
        if ($zoneId === null) {
            return ['ok' => false, 'error' => 'Edge Zone ID is missing. Add it in Settings.'];
        }

        $existing = $this->findCustomHostname($zoneId, $domain);
        if (! $existing['ok']) {
            return ['ok' => false, 'error' => $existing['error']];
        }

        $customHostname = is_array($existing['result']) ? $existing['result'] : null;
        if (! $customHostname) {
            return ['ok' => false, 'error' => 'Protected domain was not found.'];
        }

        $dnsRoute = $this->verifySaasDnsRouteSet($domain);
        if (! ($dnsRoute['ok'] ?? false) && $this->looksLikeApexDomain($domain)) {
            $apexRoute = app(DnsVerificationService::class)->verifyManagedHostname(
                $domain,
                $this->config->saasCnameTarget(),
                true
            );
            if (($apexRoute['ok'] ?? false) && ($apexRoute['flattened_apex'] ?? false)) {
                $dnsRoute = [
                    'ok' => true,
                    'reason' => $apexRoute['reason'] ?? null,
                    'checks' => [$domain => $apexRoute],
                ];
            }
        }
        $hostnameStatus = (string) ($customHostname['status'] ?? 'pending');
        if (! ($dnsRoute['ok'] ?? false)) {
            $hostnameStatus = 'pending';
        }

        $sql = sprintf(
            "UPDATE domain_configs
             SET custom_hostname_id = '%s',
                 hostname_status = '%s',
                 ssl_status = '%s',
                 ownership_verification_json = '%s',
                 updated_at = CURRENT_TIMESTAMP
             WHERE domain_name = '%s'",
            str_replace("'", "''", (string) ($customHostname['id'] ?? '')),
            str_replace("'", "''", $hostnameStatus),
            str_replace("'", "''", (string) ($customHostname['ssl']['status'] ?? 'pending_validation')),
            str_replace("'", "''", (string) json_encode($customHostname['ownership_verification'] ?? null)),
            str_replace("'", "''", $domain)
        );
        $result = $this->d1->query($sql);

        return [
            'ok' => $result['ok'],
            'error' => $result['ok'] ? null : ($result['error'] ?: 'We could not update the domain status.'),
            'custom_hostname' => $customHostname,
            'dns_route' => $dnsRoute,
        ];
    }

    public function verifySaasDnsRoute(string $domainName, ?string $expectedTarget = null): array
    {
        return app(DnsVerificationService::class)->verifyManagedHostname(
            $domainName,
            (string) ($expectedTarget ?: $this->config->saasCnameTarget()),
            false
        );
    }

    public function verifySaasDnsRouteSet(string $domainName, ?string $expectedTarget = null): array
    {
        $domain = $this->normalizeDomain($domainName);
        $checks = [];

        if ($domain !== '') {
            $checks[$domain] = $this->verifySaasDnsRoute($domain, $expectedTarget);
        }

        $failed = array_filter($checks, static fn (array $check): bool => ! ($check['ok'] ?? false));

        return [
            'ok' => $failed === [],
            'reason' => $failed === []
                ? null
                : implode(' | ', array_map(
                    static fn (string $host, array $check): string => $host.': '.(string) ($check['reason'] ?? 'DNS mismatch'),
                    array_keys($failed),
                    array_values($failed)
                )),
            'checks' => $checks,
        ];
    }

    public function deleteSaasCustomHostname(string $customHostnameId): array
    {
        $zoneId = $this->config->saasZoneId();
        $id = trim($customHostnameId);
        if ($zoneId === null || $id === '') {
            return ['ok' => true, 'error' => null, 'action' => 'skipped'];
        }

        $delete = $this->cloudflare->request('DELETE', '/zones/'.$zoneId.'/custom_hostnames/'.$id);
        if (! $delete['ok']) {
            return ['ok' => false, 'error' => $delete['error'], 'action' => 'failed'];
        }

        return ['ok' => true, 'error' => null, 'action' => 'deleted'];
    }

    public function removeDomainSecurityArtifacts(string $zoneId, string $domainName, ?string $turnstileSiteKey = null): array
    {
        $zone = trim($zoneId);
        $domain = $this->normalizeDomain($domainName);
        $siteKey = trim((string) ($turnstileSiteKey ?? ''));
        if ($zone === '' || $domain === '') {
            return ['ok' => false, 'error' => 'Zone ID or domain is empty.', 'details' => []];
        }

        $details = [];
        $routeRemoval = $this->workerRoutes->removeWorkerRoutes($zone, $domain);
        $details[] = $routeRemoval['ok']
            ? 'Worker routes removed: '.($routeRemoval['action'] ?? 'none')
            : 'Worker route cleanup failed: '.($routeRemoval['error'] ?? 'unknown error');

        if ($siteKey !== '') {
            $widgetRemoval = $this->turnstile->deleteWidgetForZone($zone, $siteKey);
            $details[] = $widgetRemoval['ok']
                ? 'Browser challenge removed.'
                : 'Browser challenge cleanup failed: '.($widgetRemoval['error'] ?? 'unknown error');
        } else {
            $details[] = 'Browser challenge key missing; cleanup skipped.';
        }

        $ok = $routeRemoval['ok'] && ($siteKey === '' || ($widgetRemoval['ok'] ?? false));

        return ['ok' => $ok, 'error' => $ok ? null : implode(' | ', $details), 'details' => $details];
    }

    private function findCustomHostname(string $zoneId, string $domainName): array
    {
        $domain = $this->normalizeDomain($domainName);
        $list = $this->cloudflare->request('GET', '/zones/'.$zoneId.'/custom_hostnames', ['hostname' => $domain, 'page' => 1, 'per_page' => 1]);
        if (! $list['ok']) {
            return ['ok' => false, 'error' => $list['error'], 'result' => null];
        }

        $rows = is_array($list['result']) ? $list['result'] : [];

        return ['ok' => true, 'error' => null, 'result' => is_array($rows[0] ?? null) ? $rows[0] : null];
    }

    private function normalizeDomain(string $domainName): string
    {
        $domain = strtolower(trim($domainName));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = explode('/', $domain, 2)[0];

        return rtrim(trim($domain), '.');
    }

    private function looksLikeApexDomain(string $domain): bool
    {
        if ($domain === '' || str_contains($domain, '*')) {
            return false;
        }

        $labels = array_values(array_filter(explode('.', $domain), fn (string $label): bool => $label !== ''));
        if (count($labels) === 2) {
            return true;
        }

        $suffix = implode('.', array_slice($labels, -2));
        $commonSecondLevelSuffixes = [
            'ac.uk', 'co.il', 'co.jp', 'co.nz', 'co.uk',
            'com.au', 'com.br', 'com.eg', 'com.mx', 'com.sa', 'com.tr', 'com.ua',
            'net.au', 'net.eg', 'net.sa', 'org.au', 'org.uk',
        ];

        return count($labels) === 3 && in_array($suffix, $commonSecondLevelSuffixes, true);
    }
}
