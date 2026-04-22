<?php

namespace App\Services\EdgeShield;

use Illuminate\Support\Facades\Http;

class CloudflareApiClient
{
    public const API_BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(private readonly EdgeShieldConfig $config) {}

    public function graphql(string $query, array $variables = []): array
    {
        $token = $this->config->cloudflareApiToken();
        if ($token === null) {
            return ['ok' => false, 'error' => 'Edge API token is missing. Add it in Settings.', 'data' => null];
        }

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withToken($token)
                ->post(self::API_BASE.'/graphql', [
                    'query' => $query,
                    'variables' => $variables,
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Edge service analytics request failed.', 'data' => null];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error' => 'Edge service analytics HTTP error: '.$response->status(),
                'data' => null,
            ];
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return ['ok' => false, 'error' => 'Unexpected edge service analytics response.', 'data' => null];
        }

        $errors = $payload['errors'] ?? null;
        if (is_array($errors) && count($errors) > 0) {
            return [
                'ok' => false,
                'error' => 'Edge service analytics reported failure.',
                'data' => $payload['data'] ?? null,
            ];
        }

        return ['ok' => true, 'error' => null, 'data' => $payload['data'] ?? null];
    }

    public function request(string $method, string $path, array $query = [], ?array $json = null): array
    {
        $token = $this->config->cloudflareApiToken();
        if ($token === null) {
            return ['ok' => false, 'error' => 'Edge API token is missing. Add it in Settings.', 'result' => null];
        }

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withToken($token)
                ->send($method, self::API_BASE.$path, [
                    'query' => $query,
                    'json' => $json,
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Edge service request failed.', 'result' => null];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error' => 'Edge service HTTP error: '.$response->status(),
                'result' => null,
            ];
        }

        $data = $response->json();
        if (! is_array($data)) {
            return ['ok' => false, 'error' => 'Unexpected edge service response.', 'result' => null];
        }

        if (($data['success'] ?? false) !== true) {
            return [
                'ok' => false,
                'error' => 'Edge service reported failure.',
                'result' => null,
            ];
        }

        return ['ok' => true, 'error' => null, 'result' => $data['result'] ?? null];
    }
}
