<?php

namespace App\Services\EdgeShield\Concerns;

use Illuminate\Support\Facades\Http;

trait SaasHostnameOriginValidationConcern
{
    public function validateOriginServerForHostname(string $domainName, string $originServer): array
    {
        $domain = $this->normalizeDomain($domainName);
        if ($domain === '') {
            return ['ok' => false, 'error' => 'Domain name is empty.'];
        }

        $origin = trim($originServer);
        if ($origin === '') {
            return ['ok' => false, 'error' => 'Origin server is required.'];
        }

        $resolvedOrigin = $this->resolveCustomOriginTarget($domain, $origin);
        if (! $resolvedOrigin['ok']) {
            return ['ok' => false, 'error' => $resolvedOrigin['error']];
        }

        $probeTarget = $this->originProbeTarget($domain, $origin);
        $headers = [
            'Host' => $domain,
            'User-Agent' => 'VerifySky-Origin-Validator/1.0',
            'X-Forwarded-Host' => $domain,
            'X-Forwarded-Proto' => 'https',
        ];

        foreach (['https://', 'http://'] as $scheme) {
            try {
                $request = Http::timeout(6)
                    ->connectTimeout(4)
                    ->withHeaders($headers)
                    ->withOptions($this->originProbeOptions($scheme, $domain, $origin));

                $response = $request->head($scheme.$probeTarget['host']);
                if ($response->status() === 405) {
                    $response = $request->get($scheme.$probeTarget['host']);
                }
            } catch (\Throwable) {
                continue;
            }

            $server = strtolower(trim((string) $response->header('server')));
            if ($response->header('cf-ray') || $response->header('cf-cache-status') || str_contains($server, 'cloudflare')) {
                return ['ok' => false, 'error' => 'This server still appears to sit behind a proxy. Enter the real hosting IP or server domain instead.'];
            }

            $status = $response->status();
            if (($status >= 200 && $status < 500) && $status !== 421) {
                return [
                    'ok' => true,
                    'error' => null,
                    'status' => $status,
                    'scheme' => rtrim($scheme, ':/'),
                    'resolved_target' => $resolvedOrigin['target'],
                ];
            }
        }

        if ($probeTarget['is_ip'] && $this->originIpAppearsLocalAndListening($origin)) {
            return [
                'ok' => true,
                'error' => null,
                'status' => 0,
                'scheme' => 'local',
                'resolved_target' => $resolvedOrigin['target'],
            ];
        }

        return ['ok' => false, 'error' => 'We could not reach the server for this domain. Enter a valid hosting IP or server domain before continuing.'];
    }

    public function detectOriginServerForInput(string $domainName): array
    {
        $requestedDomain = $this->normalizeDomain($domainName);
        if ($requestedDomain === '') {
            return ['ok' => false, 'error' => 'Domain name is empty.', 'origin_server' => null];
        }

        $protectedHosts = $this->saasHostnamesForInput($requestedDomain);
        $candidates = $this->originDetectionCandidates($requestedDomain, $protectedHosts);
        $attempts = [];

        foreach ($candidates as $candidate) {
            $detected = $this->detectOriginFromHostname($candidate, $protectedHosts);
            if ($detected['ok']) {
                return $detected;
            }

            if (! empty($detected['reason'])) {
                $attempts[] = $candidate.': '.$detected['reason'];
            }
        }

        $likelyProxy = count(array_filter(
            $attempts,
            static fn (string $attempt): bool => str_contains($attempt, 'behind an edge or DNS proxy')
        )) > 0;

        return [
            'ok' => false,
            'error' => $likelyProxy
                ? 'Automatic setup could not find the real server because this domain appears to sit behind a proxy. Enter the server IP or server domain.'
                : 'Automatic setup could not find the server for this domain. Enter the server IP or server domain.',
            'origin_server' => null,
            'attempts' => $attempts,
        ];
    }

    private function originDetectionCandidates(string $requestedDomain, array $protectedHosts): array
    {
        $candidates = [$requestedDomain];

        foreach ($protectedHosts as $hostname) {
            $normalized = $this->normalizeDomain((string) $hostname);
            if ($normalized !== '') {
                $candidates[] = $normalized;
            }

            if (str_starts_with($normalized, 'www.')) {
                $candidates[] = substr($normalized, 4);
            } elseif ($normalized !== '' && $this->looksLikeApexDomain($normalized)) {
                $candidates[] = 'www.'.$normalized;
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    private function detectOriginFromHostname(string $hostname, array $protectedHosts): array
    {
        $cnameRecords = @dns_get_record($hostname, DNS_CNAME);
        if (is_array($cnameRecords)) {
            foreach ($cnameRecords as $record) {
                $target = $this->normalizeDomain((string) ($record['target'] ?? ''));
                if ($target === '') {
                    continue;
                }

                $validation = $this->validateDetectedOriginTarget($target, $protectedHosts);
                if ($validation['ok']) {
                    return ['ok' => true, 'error' => null, 'origin_server' => $target, 'detected_from' => $hostname, 'strategy' => 'dns_cname'];
                }
            }
        }

        $aRecords = @dns_get_record($hostname, DNS_A);
        $aaaaRecords = @dns_get_record($hostname, DNS_AAAA);
        $ipRecords = array_merge(is_array($aRecords) ? $aRecords : [], is_array($aaaaRecords) ? $aaaaRecords : []);

        foreach ($ipRecords as $record) {
            $ip = trim((string) ($record['ip'] ?? $record['ipv6'] ?? ''));
            if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            if ($this->responseLooksProxiedByCloudflare($hostname)) {
                return ['ok' => false, 'error' => null, 'origin_server' => null, 'reason' => 'hostname appears to be behind an edge or DNS proxy'];
            }

            return ['ok' => true, 'error' => null, 'origin_server' => $ip, 'detected_from' => $hostname, 'strategy' => str_contains($ip, ':') ? 'dns_aaaa' : 'dns_a'];
        }

        return ['ok' => false, 'error' => null, 'origin_server' => null, 'reason' => 'no usable public DNS records found'];
    }

    private function validateDetectedOriginTarget(string $target, array $protectedHosts): array
    {
        $saasTarget = $this->config->saasCnameTarget();
        $normalizedProtectedHosts = array_values(array_unique(array_filter(array_map(
            fn (mixed $host): string => $this->normalizeDomain((string) $host),
            $protectedHosts
        ))));

        if (in_array($target, $normalizedProtectedHosts, true) || $target === $saasTarget) {
            return ['ok' => false, 'reason' => 'target points back to the protected hostname or VerifySky edge'];
        }

        return ['ok' => true, 'reason' => null];
    }

    private function responseLooksProxiedByCloudflare(string $hostname): bool
    {
        foreach (['https://', 'http://'] as $scheme) {
            try {
                $request = Http::timeout(4)
                    ->connectTimeout(3)
                    ->withHeaders(['User-Agent' => 'VerifySky-Origin-Detector/1.0'])
                    ->withOptions(['allow_redirects' => false, 'verify' => false]);

                $response = $request->head($scheme.$hostname);
                if ($response->status() === 405) {
                    $response = $request->get($scheme.$hostname);
                }
            } catch (\Throwable) {
                continue;
            }

            $server = strtolower(trim((string) $response->header('server')));
            if ($response->header('cf-ray') || $response->header('cf-cache-status') || str_contains($server, 'cloudflare')) {
                return true;
            }
        }

        return false;
    }

    private function originProbeTarget(string $domainName, string $originServer): array
    {
        $origin = trim($originServer);
        $isIp = filter_var($origin, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || filter_var($origin, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

        return [
            'host' => $isIp ? $domainName : $this->normalizeDomain($origin),
            'is_ip' => $isIp,
        ];
    }

    private function originProbeOptions(string $scheme, string $domainName, string $originServer): array
    {
        $options = ['allow_redirects' => false, 'verify' => false];
        $origin = trim($originServer);
        if (! (filter_var($origin, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || filter_var($origin, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))) {
            return $options;
        }

        $port = str_starts_with($scheme, 'https') ? 443 : 80;
        if (defined('CURLOPT_RESOLVE')) {
            $options['curl'] = [
                CURLOPT_RESOLVE => [$domainName.':'.$port.':'.$origin],
            ];
        }

        return $options;
    }

    private function originIpAppearsLocalAndListening(string $originServer): bool
    {
        $origin = trim($originServer);
        if (! filter_var($origin, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (! in_array($origin, $this->serverLocalIpAddresses(), true)) {
            return false;
        }

        return $this->canOpenTcpConnection($origin, 443) || $this->canOpenTcpConnection($origin, 80);
    }

    protected function serverLocalIpAddresses(): array
    {
        $addresses = [];

        foreach (@dns_get_record(gethostname() ?: '', DNS_A + DNS_AAAA) ?: [] as $record) {
            $ip = trim((string) ($record['ip'] ?? $record['ipv6'] ?? ''));
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                $addresses[] = $ip;
            }
        }

        foreach (explode(' ', trim((string) @shell_exec('hostname -I 2>/dev/null'))) as $ip) {
            $ip = trim($ip);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                $addresses[] = $ip;
            }
        }

        return array_values(array_unique($addresses));
    }

    protected function canOpenTcpConnection(string $ipAddress, int $port): bool
    {
        $socket = @fsockopen($ipAddress, $port, $errno, $errstr, 1.5);
        if (! is_resource($socket)) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
