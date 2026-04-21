<?php

namespace App\Services\EdgeShield;

class TurnstileService
{
    public function __construct(private readonly CloudflareApiClient $cloudflare) {}

    public function ensureWidgetForDomain(string $accountId, string $domainName, ?string $widgetName = null): array
    {
        $domain = $this->normalizeDomain($domainName);
        if ($accountId === '' || $domain === '') {
            return ['ok' => false, 'error' => 'Cloudflare account ID and domain are required.'];
        }

        $widgetCreate = $this->cloudflare->request(
            'POST',
            '/accounts/'.$accountId.'/challenges/widgets',
            [],
            [
                'name' => trim((string) ($widgetName ?? '')) !== '' ? (string) $widgetName : 'VerifySky - '.$domain,
                'domains' => $this->allowedDomains($domain),
                'mode' => 'invisible',
            ]
        );

        if (! $widgetCreate['ok']) {
            return ['ok' => false, 'error' => $widgetCreate['error']];
        }

        $widget = is_array($widgetCreate['result']) ? $widgetCreate['result'] : [];
        $siteKey = (string) ($widget['sitekey'] ?? '');
        $secret = (string) ($widget['secret'] ?? '');

        if ($siteKey !== '' && $secret === '') {
            $rotate = $this->cloudflare->request(
                'POST',
                '/accounts/'.$accountId.'/challenges/widgets/'.$siteKey.'/rotate_secret',
                [],
                ['invalidate_immediately' => false]
            );
            if ($rotate['ok']) {
                $rotateWidget = is_array($rotate['result']) ? $rotate['result'] : [];
                $secret = (string) ($rotateWidget['secret'] ?? $secret);
            }
        }

        if ($siteKey === '' || $secret === '') {
            return ['ok' => false, 'error' => 'Turnstile widget was created but keys were not returned by Cloudflare.'];
        }

        return [
            'ok' => true,
            'error' => null,
            'sitekey' => $siteKey,
            'secret' => $secret,
        ];
    }

    public function deleteWidgetForZone(string $zoneId, string $siteKey): array
    {
        $zone = trim($zoneId);
        $key = trim($siteKey);
        if ($zone === '' || $key === '') {
            return ['ok' => false, 'error' => 'Zone ID and Turnstile site key are required.'];
        }

        $account = $this->resolveZoneAccountId($zone);
        if (! $account['ok']) {
            return ['ok' => false, 'error' => $account['error']];
        }

        $delete = $this->cloudflare->request(
            'DELETE',
            '/accounts/'.$account['account_id'].'/challenges/widgets/'.$key
        );
        if (! $delete['ok']) {
            return ['ok' => false, 'error' => $delete['error']];
        }

        return ['ok' => true, 'error' => null];
    }

    public function allowedDomains(string $domainName): array
    {
        $domain = $this->normalizeDomain($domainName);
        if ($domain === '') {
            return [];
        }

        if (str_starts_with($domain, 'www.')) {
            $apex = substr($domain, 4);

            return array_values(array_unique([$domain, $apex]));
        }

        return array_values(array_unique([$domain, 'www.'.$domain]));
    }

    private function normalizeDomain(string $domainName): string
    {
        $domain = strtolower(trim($domainName));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = explode('/', $domain, 2)[0];

        return trim($domain);
    }

    private function resolveZoneAccountId(string $zoneId): array
    {
        $zone = trim($zoneId);
        if ($zone === '') {
            return ['ok' => false, 'error' => 'Zone ID is empty.', 'account_id' => null];
        }

        $zoneResp = $this->cloudflare->request('GET', '/zones/'.$zone);
        if (! $zoneResp['ok']) {
            return ['ok' => false, 'error' => $zoneResp['error'], 'account_id' => null];
        }

        $zoneRow = is_array($zoneResp['result']) ? $zoneResp['result'] : [];
        $accountId = is_string($zoneRow['account']['id'] ?? null) ? trim($zoneRow['account']['id']) : '';
        if ($accountId === '') {
            return ['ok' => false, 'error' => 'Unable to resolve Cloudflare account for the zone.', 'account_id' => null];
        }

        return ['ok' => true, 'error' => null, 'account_id' => $accountId];
    }
}
