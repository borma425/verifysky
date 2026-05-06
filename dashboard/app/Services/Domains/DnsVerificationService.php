<?php

namespace App\Services\Domains;

class DnsVerificationService
{
    /**
     * @return array{ok: bool, reason: ?string, resolved: array<int, array<string, string>>, flattened_apex: bool}
     */
    public function verifyManagedHostname(string $domainName, string $expectedTarget, bool $allowFlattenedApex = false): array
    {
        $domain = $this->normalizeDomain($domainName);
        $target = $this->normalizeDomain($expectedTarget);
        if ($domain === '' || $target === '') {
            return ['ok' => false, 'reason' => 'Domain or expected CNAME target is empty.', 'resolved' => [], 'flattened_apex' => false];
        }

        $resolved = [];
        $visited = [];
        $current = $domain;
        for ($depth = 0; $depth < 6; $depth++) {
            if (isset($visited[$current])) {
                return ['ok' => false, 'reason' => 'DNS CNAME chain loops before reaching VerifySky.', 'resolved' => $resolved, 'flattened_apex' => false];
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
                return ['ok' => true, 'reason' => null, 'resolved' => $resolved, 'flattened_apex' => false];
            }

            $current = $next;
        }

        $ipRecords = $this->ipRecords($domain);
        foreach ($ipRecords as $record) {
            $resolved[] = $record;
        }

        if ($allowFlattenedApex && $this->looksLikeApexDomain($domain) && $ipRecords !== []) {
            return [
                'ok' => true,
                'reason' => 'Apex appears to use ALIAS/ANAME/CNAME flattening.',
                'resolved' => $resolved,
                'flattened_apex' => true,
            ];
        }

        return [
            'ok' => false,
            'reason' => $resolved === []
                ? 'DNS does not currently resolve for this domain.'
                : 'DNS is not pointing at the VerifySky CNAME target.',
            'resolved' => $resolved,
            'flattened_apex' => false,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function hostnamesForInput(string $domainName, string $apexMode = 'www_redirect'): array
    {
        $domain = $this->normalizeDomain($domainName);
        if ($domain === '') {
            return [];
        }

        if (! $this->looksLikeApexDomain($domain) || str_starts_with($domain, 'www.')) {
            return [$domain];
        }

        return match ($apexMode) {
            'direct_apex' => [$domain, 'www.'.$domain],
            'subdomain_only' => [$domain],
            default => ['www.'.$domain],
        };
    }

    public function canonicalHostname(string $domainName, string $apexMode = 'www_redirect'): string
    {
        $domain = $this->normalizeDomain($domainName);
        if ($domain === '') {
            return '';
        }

        if ($this->looksLikeApexDomain($domain) && $apexMode === 'www_redirect') {
            return 'www.'.$domain;
        }

        return $domain;
    }

    public function normalizeDomain(string $domainName): string
    {
        $domain = strtolower(trim($domainName));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = explode('/', $domain, 2)[0];

        return rtrim(trim($domain), '.');
    }

    public function looksLikeApexDomain(string $domain): bool
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

    /**
     * @return array<int, array{type: string, name: string, target: string}>
     */
    private function ipRecords(string $domain): array
    {
        $aRecords = @dns_get_record($domain, DNS_A);
        $aaaaRecords = @dns_get_record($domain, DNS_AAAA);
        $records = array_merge(is_array($aRecords) ? $aRecords : [], is_array($aaaaRecords) ? $aaaaRecords : []);
        $resolved = [];
        foreach ($records as $record) {
            $ip = trim((string) ($record['ip'] ?? $record['ipv6'] ?? ''));
            if ($ip !== '') {
                $resolved[] = ['type' => isset($record['ipv6']) ? 'AAAA' : 'A', 'name' => $domain, 'target' => $ip];
            }
        }

        return $resolved;
    }
}
