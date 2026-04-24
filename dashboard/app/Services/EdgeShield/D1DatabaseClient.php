<?php

namespace App\Services\EdgeShield;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class D1DatabaseClient
{
    public function __construct(
        private readonly EdgeShieldConfig $config,
        private readonly WranglerProcessRunner $runner
    ) {}

    public function query(string $sql, int $timeout = 90): array
    {
        $normalizedTimeout = max(10, min(300, $timeout));
        if (! $this->config->canRunD1Query($sql)) {
            return [
                'ok' => false,
                'error' => $this->config->mutationBlockedError(),
                'output' => '',
            ];
        }

        if ($this->shouldUseReadCache($sql)) {
            $cached = $this->getCachedReadResult($sql);
            if ($cached !== null) {
                return $cached;
            }
        }

        $result = $this->performQuery($sql, $normalizedTimeout);

        if (($result['ok'] ?? false) !== true) {
            return $result;
        }

        if ($this->shouldUseReadCache($sql)) {
            $this->storeCachedReadResult($sql, $result);
        } elseif ($this->shouldInvalidateReadCache($sql)) {
            $this->bumpReadCacheNamespaceVersion();
        }

        return $result;
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

        $decoded = json_decode(substr($raw, $start), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function queryViaWrangler(string $sql, int $timeout, bool $local): array
    {
        $environmentFlag = '';
        $environmentName = $this->config->wranglerEnvironmentName();
        if ($environmentName !== null) {
            $environmentFlag = ' --env '.escapeshellarg($environmentName);
        }

        $persistFlag = '';
        if ($local) {
            $persistFlag = ' --persist-to '.escapeshellarg($this->config->localD1PersistPath());
        }

        $cmd = sprintf(
            '%s d1 execute %s%s%s %s --command %s',
            $this->config->wranglerBin(),
            escapeshellarg($this->config->d1DatabaseName()),
            $environmentFlag,
            $persistFlag,
            $local ? '--local' : '--remote',
            escapeshellarg($sql)
        );

        return $this->runner->runInProject($cmd, max(10, min(300, $timeout)));
    }

    private function queryViaApi(string $accountId, string $token, string $databaseId, string $sql, int $timeout): array
    {
        try {
            $response = Http::timeout(max(10, min(300, $timeout)))
                ->acceptJson()
                ->withToken($token)
                ->post(CloudflareApiClient::API_BASE.'/accounts/'.$accountId.'/d1/database/'.$databaseId.'/query', [
                    'sql' => $sql,
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Edge database request failed.', 'output' => ''];
        }

        $payload = $response->json();
        if (! $response->successful() || ! is_array($payload) || ($payload['success'] ?? false) !== true) {
            return [
                'ok' => false,
                'error' => 'Edge database API HTTP error: '.$response->status(),
                'output' => is_array($payload) ? json_encode($payload) : (string) $response->body(),
            ];
        }

        return ['ok' => true, 'error' => null, 'output' => json_encode($payload['result'] ?? [])];
    }

    private function performQuery(string $sql, int $timeout): array
    {
        if ($this->config->useLocalD1()) {
            return $this->queryViaWrangler($sql, $timeout, true);
        }

        $remoteConfig = $this->resolveRemoteApiConfig();
        if (($remoteConfig['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'error' => (string) ($remoteConfig['error'] ?? 'Edge database API configuration is incomplete.'),
                'output' => '',
            ];
        }

        return $this->queryViaApi(
            (string) $remoteConfig['account_id'],
            (string) $remoteConfig['api_token'],
            (string) $remoteConfig['database_id'],
            $sql,
            $timeout
        );
    }

    private function resolveRemoteApiConfig(): array
    {
        $accountId = $this->config->cloudflareAccountId();
        $token = $this->config->cloudflareApiToken();
        $databaseId = $this->config->targetD1DatabaseId();

        $missing = [];
        if ($accountId === null) {
            $missing[] = 'Edge Account ID';
        }
        if ($token === null) {
            $missing[] = 'Edge API Token';
        }
        if ($databaseId === null) {
            $missing[] = 'D1_DATABASE_ID';
        }

        if ($missing !== []) {
            return [
                'ok' => false,
                'error' => 'Edge database API configuration is incomplete. Set '.implode(', ', $missing).'. Remote database fallback is disabled.',
            ];
        }

        return [
            'ok' => true,
            'account_id' => $accountId,
            'api_token' => $token,
            'database_id' => $databaseId,
        ];
    }

    private function shouldUseReadCache(string $sql): bool
    {
        return $this->config->useLocalD1()
            && $this->config->d1ReadCacheTtl() > 0
            && in_array($this->leadingSqlKeyword($sql), ['SELECT', 'WITH'], true);
    }

    private function shouldInvalidateReadCache(string $sql): bool
    {
        return ! in_array($this->leadingSqlKeyword($sql), ['SELECT', 'WITH'], true);
    }

    private function getCachedReadResult(string $sql): ?array
    {
        $cached = Cache::get($this->readCacheKey($sql));

        return is_array($cached) && array_key_exists('ok', $cached) ? $cached : null;
    }

    private function storeCachedReadResult(string $sql, array $result): void
    {
        Cache::put($this->readCacheKey($sql), $result, $this->config->d1ReadCacheTtl());
    }

    private function bumpReadCacheNamespaceVersion(): void
    {
        $key = $this->readCacheNamespaceKey();
        $currentVersion = (int) Cache::get($key, 1);

        Cache::forever($key, max(1, $currentVersion) + 1);
    }

    private function readCacheKey(string $sql): string
    {
        return implode(':', [
            'edge_shield',
            'd1',
            'read_cache',
            $this->transportMode(),
            md5($this->config->d1CacheIdentifier()),
            $this->readCacheNamespaceVersion(),
            md5(json_encode([
                'sql' => $this->normalizeSql($sql),
                'bindings' => [],
            ], JSON_THROW_ON_ERROR)),
        ]);
    }

    private function readCacheNamespaceVersion(): int
    {
        return max(1, (int) Cache::get($this->readCacheNamespaceKey(), 1));
    }

    private function readCacheNamespaceKey(): string
    {
        return implode(':', [
            'edge_shield',
            'd1',
            'read_cache_namespace',
            $this->transportMode(),
            md5($this->config->d1CacheIdentifier()),
        ]);
    }

    private function transportMode(): string
    {
        return $this->config->useLocalD1() ? 'local' : 'non_local';
    }

    private function normalizeSql(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    private function leadingSqlKeyword(string $sql): string
    {
        if (preg_match('/^\s*([a-zA-Z]+)/', $sql, $matches) !== 1) {
            return '';
        }

        return strtoupper($matches[1]);
    }
}
