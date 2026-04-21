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
            return ['ok' => false, 'error' => 'Cloudflare API token is missing. Add CF API Token in Settings.', 'data' => null];
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
            return ['ok' => false, 'error' => 'Cloudflare GraphQL request failed: '.$e->getMessage(), 'data' => null];
        }

        if (! $response->successful()) {
            $payload = $response->json();
            $message = is_array($payload) ? ($payload['errors'][0]['message'] ?? null) : null;

            return [
                'ok' => false,
                'error' => $message
                    ? 'Cloudflare GraphQL HTTP error: '.$response->status().' ('.$message.')'
                    : 'Cloudflare GraphQL HTTP error: '.$response->status(),
                'data' => null,
            ];
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return ['ok' => false, 'error' => 'Unexpected Cloudflare GraphQL response.', 'data' => null];
        }

        $errors = $payload['errors'] ?? null;
        if (is_array($errors) && count($errors) > 0) {
            $message = $errors[0]['message'] ?? null;

            return [
                'ok' => false,
                'error' => $message ? 'Cloudflare GraphQL error: '.$message : 'Cloudflare GraphQL reported failure.',
                'data' => $payload['data'] ?? null,
            ];
        }

        return ['ok' => true, 'error' => null, 'data' => $payload['data'] ?? null];
    }

    public function request(string $method, string $path, array $query = [], ?array $json = null): array
    {
        $token = $this->config->cloudflareApiToken();
        if ($token === null) {
            return ['ok' => false, 'error' => 'Cloudflare API token is missing. Add CF API Token in Settings.', 'result' => null];
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
            return ['ok' => false, 'error' => 'Cloudflare API request failed: '.$e->getMessage(), 'result' => null];
        }

        if (! $response->successful()) {
            $payload = $response->json();
            $message = is_array($payload) ? ($payload['errors'][0]['message'] ?? null) : null;

            return [
                'ok' => false,
                'error' => $message
                    ? 'Cloudflare API HTTP error: '.$response->status().' ('.$message.')'
                    : 'Cloudflare API HTTP error: '.$response->status(),
                'result' => null,
            ];
        }

        $data = $response->json();
        if (! is_array($data)) {
            return ['ok' => false, 'error' => 'Unexpected Cloudflare API response.', 'result' => null];
        }

        if (($data['success'] ?? false) !== true) {
            $message = $data['errors'][0]['message'] ?? null;

            return [
                'ok' => false,
                'error' => $message ? 'Cloudflare API error: '.$message : 'Cloudflare API reported failure.',
                'result' => null,
            ];
        }

        return ['ok' => true, 'error' => null, 'result' => $data['result'] ?? null];
    }
}
