<?php

namespace App\Services\EdgeShield\Concerns;

trait SaasHostnameProvisioningConcern
{
    public function autoProvisionDomainConfig(
        string $domainName,
        ?string $zoneId = null,
        ?string $turnstileSiteKey = null,
        ?string $turnstileSecret = null
    ): array {
        $domain = $this->normalizeDomain($domainName);
        $resolvedZoneId = trim((string) ($zoneId ?? ''));
        $resolvedSiteKey = trim((string) ($turnstileSiteKey ?? ''));
        $resolvedSecret = trim((string) ($turnstileSecret ?? ''));
        $zoneAccountId = null;

        if ($resolvedZoneId === '') {
            $zoneLookup = $this->cloudflare->request('GET', '/zones', [
                'name' => $domain,
                'status' => 'active',
                'page' => 1,
                'per_page' => 1,
                'match' => 'all',
            ]);
            if (! $zoneLookup['ok']) {
                return ['ok' => false, 'error' => $zoneLookup['error']];
            }

            $zone = is_array($zoneLookup['result'][0] ?? null) ? $zoneLookup['result'][0] : null;
            if (! $zone || ! is_string($zone['id'] ?? null)) {
                return ['ok' => false, 'error' => 'Zone not found for this domain. Make sure the domain is added and active in the same edge account.'];
            }

            $resolvedZoneId = $zone['id'];
            $zoneAccountId = is_string($zone['account']['id'] ?? null) ? $zone['account']['id'] : null;
        }

        if ($resolvedSiteKey === '' || $resolvedSecret === '') {
            $accountId = $this->config->cloudflareAccountId() ?: $zoneAccountId;
            if (! $accountId) {
                return ['ok' => false, 'error' => 'Edge Account ID is required to auto-create the browser challenge. Add it in Settings.'];
            }

            $widget = $this->turnstile->ensureWidgetForDomain($accountId, $domain, 'VerifySky Challenge - '.$domain);
            if (! $widget['ok']) {
                return ['ok' => false, 'error' => $widget['error']];
            }
            if ($resolvedSiteKey === '') {
                $resolvedSiteKey = (string) ($widget['sitekey'] ?? '');
            }
            if ($resolvedSecret === '') {
                $resolvedSecret = (string) ($widget['secret'] ?? '');
            }
        }

        if ($resolvedZoneId === '' || $resolvedSiteKey === '' || $resolvedSecret === '') {
            return ['ok' => false, 'error' => 'Automatic provisioning completed partially. Missing Edge Zone ID or browser challenge keys.'];
        }

        $cacheRuleResult = $this->saasSecurity->ensureCacheRuleForEdgeShield($resolvedZoneId, $domain);

        return [
            'ok' => true,
            'error' => $cacheRuleResult['ok'] ? null : 'Browser challenge was created, but cache rule sync failed: '.($cacheRuleResult['error'] ?? 'Unknown error'),
            'domain_name' => $domain,
            'zone_id' => $resolvedZoneId,
            'turnstile_sitekey' => $resolvedSiteKey,
            'turnstile_secret' => $resolvedSecret,
            'cache_rule_action' => $cacheRuleResult['action'] ?? null,
        ];
    }

    public function updateSaasCustomOrigin(string $hostnameId, string $originServer): array
    {
        $zoneId = $this->config->saasZoneId();
        if ($zoneId === null) {
            return ['ok' => false, 'error' => 'Edge Zone ID is missing. Add it in Settings.'];
        }

        $resolvedOrigin = $this->resolveCustomOriginTarget('', $originServer);
        if (! $resolvedOrigin['ok']) {
            return ['ok' => false, 'error' => $resolvedOrigin['error']];
        }

        $update = $this->cloudflare->request(
            'PATCH',
            '/zones/'.$zoneId.'/custom_hostnames/'.$hostnameId,
            [],
            ['custom_origin_server' => $resolvedOrigin['target'], 'ssl' => ['method' => 'http', 'type' => 'dv']]
        );

        return $update['ok']
            ? ['ok' => true, 'result' => $update['result'], 'effective_origin_server' => $resolvedOrigin['target']]
            : ['ok' => false, 'error' => $update['error']];
    }

    public function provisionSaasCustomHostname(string $domainName, string $originServer): array
    {
        $domain = $this->normalizeDomain($domainName);
        $zoneId = $this->config->saasZoneId();
        $cnameTarget = $this->config->saasCnameTarget();
        if ($domain === '') {
            return ['ok' => false, 'error' => 'Domain name is empty.'];
        }
        if ($zoneId === null) {
            return ['ok' => false, 'error' => 'Edge Zone ID is missing. Add it in Settings.'];
        }

        $resolvedOrigin = $this->resolveCustomOriginTarget($domain, $originServer);
        if (! $resolvedOrigin['ok']) {
            return ['ok' => false, 'error' => $resolvedOrigin['error']];
        }

        $existing = $this->findCustomHostname($zoneId, $domain);
        if (! $existing['ok']) {
            return ['ok' => false, 'error' => $existing['error']];
        }

        $customHostname = is_array($existing['result']) ? $existing['result'] : null;
        $action = 'already_exists';
        if (! $customHostname) {
            $create = $this->cloudflare->request(
                'POST',
                '/zones/'.$zoneId.'/custom_hostnames',
                [],
                ['hostname' => $domain, 'custom_origin_server' => $resolvedOrigin['target'], 'ssl' => ['method' => 'http', 'type' => 'dv']]
            );
            if (! $create['ok']) {
                return ['ok' => false, 'error' => $create['error']];
            }

            $customHostname = is_array($create['result']) ? $create['result'] : [];
            $action = 'created';
        } elseif (($customHostname['custom_origin_server'] ?? null) !== $resolvedOrigin['target']) {
            $update = $this->cloudflare->request(
                'PATCH',
                '/zones/'.$zoneId.'/custom_hostnames/'.((string) ($customHostname['id'] ?? '')),
                [],
                ['custom_origin_server' => $resolvedOrigin['target'], 'ssl' => ['method' => 'http', 'type' => 'dv']]
            );
            if (! $update['ok']) {
                return ['ok' => false, 'error' => $update['error']];
            }

            $customHostname = is_array($update['result']) ? $update['result'] : $customHostname;
            $action = 'updated_origin';
        }

        $accountId = $this->config->cloudflareAccountId();
        if (! $accountId) {
            return ['ok' => false, 'error' => 'Edge Account ID is missing. Add it in Settings.'];
        }
        $widget = $this->turnstile->ensureWidgetForDomain($accountId, $domain);
        if (! $widget['ok']) {
            return ['ok' => false, 'error' => $widget['error']];
        }

        $botManagement = $this->saasSecurity->ensureSaasBotManagementSettings();
        $edgeRules = $this->saasSecurity->ensureSaasFallbackBypassRules();

        return [
            'ok' => true,
            'error' => null,
            'action' => $action,
            'domain_name' => $domain,
            'zone_id' => $zoneId,
            'cname_target' => $cnameTarget,
            'custom_hostname_id' => (string) ($customHostname['id'] ?? ''),
            'hostname_status' => (string) ($customHostname['status'] ?? 'pending'),
            'ssl_status' => (string) ($customHostname['ssl']['status'] ?? 'pending_validation'),
            'ownership_verification_json' => json_encode($customHostname['ownership_verification'] ?? null),
            'effective_origin_server' => $resolvedOrigin['target'],
            'turnstile_sitekey' => (string) ($widget['sitekey'] ?? ''),
            'turnstile_secret' => (string) ($widget['secret'] ?? ''),
            'bot_management_action' => $botManagement['action'] ?? null,
            'bot_management_warning' => $botManagement['ok'] ? null : ($botManagement['error'] ?? 'Bot protection settings were not synced.'),
            'edge_rules_action' => $edgeRules['action'] ?? null,
            'edge_rules_warning' => $edgeRules['ok'] ? null : ($edgeRules['error'] ?? 'Edge bypass rules were not synced.'),
        ];
    }
}
