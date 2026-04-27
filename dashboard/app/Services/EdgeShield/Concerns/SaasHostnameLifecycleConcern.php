<?php

namespace App\Services\EdgeShield\Concerns;

trait SaasHostnameLifecycleConcern
{
    public function saasHostnamesForInput(string $domainName): array
    {
        $domain = $this->normalizeDomain($domainName);
        if ($domain === '') {
            return [];
        }
        if (str_starts_with($domain, 'www.')) {
            return [$domain];
        }
        if ($this->looksLikeApexDomain($domain)) {
            return ['www.'.$domain];
        }

        return [$domain];
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
            return ['ok' => false, 'error' => 'Protected hostname was not found in edge services.'];
        }

        $dnsRoute = $this->verifySaasDnsRoute($domain);
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
            'error' => $result['ok'] ? null : ($result['error'] ?: 'Failed to update D1 hostname status.'),
            'custom_hostname' => $customHostname,
            'dns_route' => $dnsRoute,
        ];
    }

    public function verifySaasDnsRoute(string $domainName, ?string $expectedTarget = null): array
    {
        $domain = $this->normalizeDomain($domainName);
        $target = $this->normalizeDomain((string) ($expectedTarget ?: $this->config->saasCnameTarget()));
        if ($domain === '' || $target === '') {
            return ['ok' => false, 'reason' => 'Domain or expected CNAME target is empty.', 'resolved' => []];
        }

        $resolved = [];
        $visited = [];
        $current = $domain;
        for ($depth = 0; $depth < 6; $depth++) {
            if (isset($visited[$current])) {
                return ['ok' => false, 'reason' => 'DNS CNAME chain loops before reaching VerifySky.', 'resolved' => $resolved];
            }
            $visited[$current] = true;

            $cnameRecords = @dns_get_record($current, DNS_CNAME);
            if (! is_array($cnameRecords) || $cnameRecords === []) {
                break;
            }

            $next = '';
            foreach ($cnameRecords as $record) {
                $candidate = $this->normalizeDomain((string) ($record['target'] ?? ''));
                if ($candidate !== '') {
                    $next = $candidate;
                    break;
                }
            }

            if ($next === '') {
                break;
            }

            $resolved[] = ['type' => 'CNAME', 'name' => $current, 'target' => $next];
            if ($next === $target) {
                return ['ok' => true, 'reason' => null, 'resolved' => $resolved];
            }

            $current = $next;
        }

        $aRecords = @dns_get_record($domain, DNS_A);
        $aaaaRecords = @dns_get_record($domain, DNS_AAAA);
        $ipRecords = array_merge(is_array($aRecords) ? $aRecords : [], is_array($aaaaRecords) ? $aaaaRecords : []);
        foreach ($ipRecords as $record) {
            $ip = trim((string) ($record['ip'] ?? $record['ipv6'] ?? ''));
            if ($ip !== '') {
                $resolved[] = ['type' => isset($record['ipv6']) ? 'AAAA' : 'A', 'name' => $domain, 'target' => $ip];
            }
        }

        return [
            'ok' => false,
            'reason' => $resolved === []
                ? 'DNS does not currently resolve for this hostname.'
                : 'DNS is not pointing at the VerifySky CNAME target.',
            'resolved' => $resolved,
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
