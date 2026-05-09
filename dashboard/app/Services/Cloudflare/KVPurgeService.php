<?php

namespace App\Services\Cloudflare;

use App\Services\Domains\DnsVerificationService;
use App\Services\EdgeShield\EdgeShieldConfig;
use Illuminate\Support\Facades\Http;

class KVPurgeService
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    public function purgeDomain(string $domain): array
    {
        $config = app(EdgeShieldConfig::class);
        if (! $config->allowsCloudflareMutations()) {
            return [
                'ok' => false,
                'deleted' => [],
                'errors' => [$config->mutationBlockedError()],
            ];
        }

        $keys = $this->keysForDomain($domain);
        if ($keys === []) {
            return ['ok' => true, 'deleted' => [], 'errors' => []];
        }

        $accountId = trim((string) config('edgeshield.cloudflare_account_id', ''));
        $namespaceId = trim((string) config('edgeshield.runtime_kv_namespace_id', ''));
        $token = trim((string) config('edgeshield.cloudflare_api_token', ''));

        if ($accountId === '' || $namespaceId === '' || $token === '') {
            return [
                'ok' => false,
                'deleted' => [],
                'errors' => ['Edge cache account, namespace, or API token is missing.'],
            ];
        }

        $deleted = [];
        $errors = [];

        foreach ($keys as $key) {
            try {
                $response = Http::timeout(10)
                    ->acceptJson()
                    ->withToken($token)
                    ->delete(sprintf(
                        '%s/accounts/%s/storage/kv/namespaces/%s/values/%s',
                        self::API_BASE,
                        rawurlencode($accountId),
                        rawurlencode($namespaceId),
                        rawurlencode($key)
                    ));
            } catch (\Throwable $e) {
                $errors[] = $key.': request failed';

                continue;
            }

            if ($response->successful() || $response->status() === 404) {
                $deleted[] = $key;

                continue;
            }

            $payload = $response->json();
            $message = is_array($payload) ? (string) ($payload['errors'][0]['message'] ?? 'delete failed') : 'delete failed';
            $errors[] = $key.': '.$message;
        }

        return [
            'ok' => $errors === [],
            'deleted' => $deleted,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function keysForDomain(string $domain): array
    {
        $keys = [];
        foreach ($this->domainVariants($domain) as $variant) {
            $keys[] = 'cfg:'.$variant;
            $keys[] = 'dcfg:'.$variant;
            $keys[] = 'ipr:'.$variant;
            $keys[] = 'cfr:'.$variant;
            $keys[] = 'cfr:sensitive_paths:'.$variant;
        }

        if ($this->normalizeDomain($domain) === 'global') {
            $keys[] = 'cfg:global';
            $keys[] = 'cfr:global';
            $keys[] = 'cfr:sensitive_paths:global';
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<int, string>
     */
    private function domainVariants(string $domain): array
    {
        $normalized = $this->normalizeDomain($domain);
        if ($normalized === '') {
            return [];
        }

        if ($normalized === 'global') {
            return ['global'];
        }

        $variants = [$normalized];
        if (str_starts_with($normalized, 'www.') && strlen($normalized) > 4) {
            $baseDomain = substr($normalized, 4);
            if ($this->looksLikeApexDomain($baseDomain)) {
                $variants[] = $baseDomain;
            }
        } elseif ($this->looksLikeApexDomain($normalized)) {
            $variants[] = 'www.'.$normalized;
        }

        return array_values(array_unique($variants));
    }

    private function normalizeDomain(string $domain): string
    {
        return strtolower(trim($domain));
    }

    private function looksLikeApexDomain(string $domain): bool
    {
        return app(DnsVerificationService::class)->looksLikeApexDomain($domain);
    }
}
