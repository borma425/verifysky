<?php

namespace App\Services\EdgeShield;

use Illuminate\Support\Facades\Http;

class WorkerAdminClient
{
    public function __construct(private readonly EdgeShieldConfig $config) {}

    public function allowIp(string $domain, string $ip, int $ttlHours = 24, string $reason = 'dashboard manual allow from logs'): array
    {
        if (! $this->config->allowsCloudflareMutations()) {
            return ['ok' => false, 'error' => $this->config->mutationBlockedError()];
        }

        $host = $this->normalizeDomain($domain);
        if ($host === '') {
            return ['ok' => false, 'error' => 'Domain is required to call worker admin endpoint.'];
        }

        $token = $this->adminToken();
        if ($token === '') {
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
        if (! $response->ok()) {
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

        if (! is_array($payload) || (($payload['success'] ?? false) !== true)) {
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

    public function status(string $domain, string $ip): array
    {
        $host = $this->normalizeDomain($domain);
        if ($host === '') {
            return ['ok' => false, 'error' => 'Domain is required to call worker admin endpoint.'];
        }
        if (trim($ip) === '') {
            return ['ok' => false, 'error' => 'IP is required.'];
        }

        $token = $this->adminToken();
        if ($token === '') {
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
        if (! $response->ok()) {
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

        if (! is_array($payload) || (($payload['success'] ?? false) !== true)) {
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

    public function blockIp(string $domain, string $ip, int $ttlHours = 24, string $reason = 'dashboard manual block from logs'): array
    {
        if (! $this->config->allowsCloudflareMutations()) {
            return ['ok' => false, 'error' => $this->config->mutationBlockedError()];
        }

        $host = $this->normalizeDomain($domain);
        if ($host === '') {
            return ['ok' => false, 'error' => 'Domain is required to call worker admin endpoint.'];
        }

        $token = $this->adminToken();
        if ($token === '') {
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
        if (! $response->ok()) {
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

        if (! is_array($payload) || (($payload['success'] ?? false) !== true)) {
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

    public function revokeAllowIp(string $domain, string $ip): array
    {
        return $this->post($domain, '/es-admin/ip/revoke-allow', ['ip' => $ip]);
    }

    public function unbanIp(string $domain, string $ip): array
    {
        return $this->post($domain, '/es-admin/ip/unban', ['ip' => $ip]);
    }

    private function post(string $domain, string $path, array $payload): array
    {
        if (! $this->config->allowsCloudflareMutations()) {
            return ['ok' => false, 'error' => $this->config->mutationBlockedError()];
        }

        $host = $this->normalizeDomain($domain);
        if ($host === '') {
            return ['ok' => false, 'error' => 'Domain is required to call worker admin endpoint.'];
        }

        $token = $this->adminToken();
        if ($token === '') {
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
        if (! $response->ok() || ! is_array($data) || (($data['success'] ?? false) !== true)) {
            $message = is_array($data) ? (string) ($data['error']['message'] ?? $data['message'] ?? 'Worker admin reported failure.') : 'Unexpected response.';

            return ['ok' => false, 'error' => $message];
        }

        return ['ok' => true, 'error' => null, 'result' => $data];
    }

    private function adminToken(): string
    {
        $runtimeEnv = $this->config->workerRuntimeEnvironment();

        return trim((string) ($runtimeEnv['ES_ADMIN_TOKEN'] ?? ''));
    }

    private function normalizeDomain(string $domainName): string
    {
        $domain = strtolower(trim($domainName));
        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = explode('/', $domain, 2)[0];

        return trim($domain);
    }
}
