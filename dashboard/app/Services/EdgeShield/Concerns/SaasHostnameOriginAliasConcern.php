<?php

namespace App\Services\EdgeShield\Concerns;

trait SaasHostnameOriginAliasConcern
{
    private function resolveCustomOriginTarget(string $domainName, string $originServer): array
    {
        $origin = trim($originServer);
        if ($origin === '') {
            return ['ok' => false, 'error' => 'Origin server is required.'];
        }

        if (filter_var($origin, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || filter_var($origin, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->ensureOriginAliasRecord($domainName, $origin);
        }

        $host = $this->normalizeDomain($origin);
        if ($host === '') {
            return ['ok' => false, 'error' => 'Origin server hostname is invalid.'];
        }

        $protectedHost = $this->normalizeDomain($domainName);
        $saasTarget = $this->config->saasCnameTarget();
        $protectedVariants = array_values(array_unique(array_filter([
            $protectedHost,
            str_starts_with($protectedHost, 'www.') ? substr($protectedHost, 4) : null,
            $protectedHost !== '' && ! str_starts_with($protectedHost, 'www.') ? 'www.'.$protectedHost : null,
        ])));

        if (in_array($host, $protectedVariants, true) || $host === $saasTarget) {
            return ['ok' => false, 'error' => 'Origin server cannot point to the protected hostname or SaaS CNAME target. Use the real backend hostname or IP.'];
        }

        return ['ok' => true, 'error' => null, 'target' => $host];
    }

    private function ensureOriginAliasRecord(string $domainName, string $ipAddress): array
    {
        $zoneId = $this->config->saasZoneId();
        if ($zoneId === null) {
            return ['ok' => false, 'error' => 'Edge Zone ID is missing. Add it in Settings.'];
        }

        $zoneBaseDomain = $this->originAliasBaseDomain();
        if ($zoneBaseDomain === '') {
            return ['ok' => false, 'error' => 'Could not derive the VerifySky zone domain for origin alias records.'];
        }

        $label = $this->originAliasLabel($domainName, $ipAddress);
        $fqdn = $label.'.'.$zoneBaseDomain;
        $recordType = filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A';

        $lookup = $this->cloudflare->request('GET', '/zones/'.$zoneId.'/dns_records', [
            'name' => $fqdn,
            'page' => 1,
            'per_page' => 1,
        ]);
        if (! $lookup['ok']) {
            return ['ok' => false, 'error' => $lookup['error']];
        }

        $existing = is_array($lookup['result'][0] ?? null) ? $lookup['result'][0] : null;
        $payload = [
            'type' => $recordType,
            'name' => $fqdn,
            'content' => $ipAddress,
            'ttl' => 1,
            'proxied' => false,
        ];

        if ($existing && is_string($existing['id'] ?? null)) {
            $existingType = strtoupper((string) ($existing['type'] ?? ''));
            $existingContent = trim((string) ($existing['content'] ?? ''));
            $existingProxied = (bool) ($existing['proxied'] ?? false);
            if ($existingType !== $recordType || $existingContent !== $ipAddress || $existingProxied !== false) {
                $update = $this->cloudflare->request(
                    'PUT',
                    '/zones/'.$zoneId.'/dns_records/'.$existing['id'],
                    [],
                    $payload
                );
                if (! $update['ok']) {
                    return ['ok' => false, 'error' => $update['error']];
                }
            }
        } else {
            $create = $this->cloudflare->request(
                'POST',
                '/zones/'.$zoneId.'/dns_records',
                [],
                $payload
            );
            if (! $create['ok']) {
                return ['ok' => false, 'error' => $create['error']];
            }
        }

        return ['ok' => true, 'error' => null, 'target' => $fqdn];
    }

    private function originAliasBaseDomain(): string
    {
        $target = $this->config->saasCnameTarget();
        $labels = array_values(array_filter(explode('.', $target), fn (string $label): bool => $label !== ''));
        if (count($labels) < 2) {
            return '';
        }

        return implode('.', array_slice($labels, -2));
    }

    private function originAliasLabel(string $domainName, string $ipAddress): string
    {
        $seed = strtolower(trim($domainName)) ?: $ipAddress;
        $slug = preg_replace('/[^a-z0-9]+/', '-', $seed) ?? 'origin';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'origin';
        }
        $slug = substr($slug, 0, 40);

        return 'origin-'.$slug.'-'.substr(sha1($seed.'|'.$ipAddress), 0, 8);
    }
}
