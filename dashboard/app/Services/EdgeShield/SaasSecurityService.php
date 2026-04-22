<?php

namespace App\Services\EdgeShield;

class SaasSecurityService
{
    public function __construct(
        private readonly CloudflareApiClient $cloudflare,
        private readonly D1DatabaseClient $d1,
        private readonly EdgeShieldConfig $config
    ) {}

    public function ensureCacheRuleForEdgeShield(string $zoneId, string $triggeringDomain): array
    {
        $zone = trim($zoneId);
        if ($zone === '') {
            return ['ok' => false, 'error' => 'Zone ID is empty.'];
        }

        $sql = sprintf(
            "SELECT domain_name FROM domain_configs WHERE zone_id = '%s' AND status = 'active'",
            str_replace("'", "''", $zone)
        );
        $result = $this->d1->query($sql);
        $domains = [];
        if ($result['ok']) {
            $rows = $this->d1->parseWranglerJson($result['output'])[0]['results'] ?? [];
            foreach ($rows as $row) {
                if (! empty($row['domain_name'])) {
                    $domain = strtolower(trim((string) $row['domain_name']));
                    $domains[] = $domain;
                    if (! str_starts_with($domain, 'www.')) {
                        $domains[] = 'www.'.$domain;
                    }
                }
            }
        }

        $trigger = strtolower(trim($triggeringDomain));
        if ($trigger !== '') {
            $domains[] = $trigger;
            if (! str_starts_with($trigger, 'www.')) {
                $domains[] = 'www.'.$trigger;
            }
        }
        $domains = array_values(array_unique($domains));
        if (empty($domains)) {
            return ['ok' => true, 'action' => 'skipped'];
        }

        $ruleDescription = 'Edge Shield Cache Protection - Bypass Cache without Session';
        $hostList = implode(' ', array_map(fn ($d) => '"'.$d.'"', $domains));
        $expression = sprintf('(http.host in {%s} and not http.cookie contains "es_session=")', $hostList);
        $newRule = [
            'description' => $ruleDescription,
            'expression' => $expression,
            'action' => 'set_cache_settings',
            'action_parameters' => ['cache' => false],
        ];

        $lookup = $this->cloudflare->request('GET', '/zones/'.$zone.'/rulesets', ['phase' => 'http_request_cache_settings']);
        if (! $lookup['ok']) {
            return ['ok' => false, 'error' => 'Failed to lookup Cache Rulesets phase: '.$lookup['error']];
        }

        $targetRulesetId = null;
        foreach (($lookup['result'] ?? []) as $rs) {
            if (is_array($rs) && ($rs['phase'] ?? '') === 'http_request_cache_settings' && ($rs['kind'] ?? '') === 'zone') {
                $targetRulesetId = $rs['id'] ?? null;
                break;
            }
        }

        if (! $targetRulesetId) {
            $create = $this->cloudflare->request('POST', '/zones/'.$zone.'/rulesets', [], [
                'name' => 'default',
                'description' => 'Zone Cache Rules created by Edge Shield',
                'kind' => 'zone',
                'phase' => 'http_request_cache_settings',
                'rules' => [$newRule],
            ]);
            if (! $create['ok']) {
                return ['ok' => false, 'error' => 'Failed to create Cache Ruleset: '.$create['error']];
            }

            return ['ok' => true, 'error' => null, 'action' => 'created'];
        }

        $rulesetDetails = $this->cloudflare->request('GET', '/zones/'.$zone.'/rulesets/'.$targetRulesetId);
        if (! $rulesetDetails['ok']) {
            return ['ok' => false, 'error' => 'Failed to fetch existing Cache Ruleset details: '.$rulesetDetails['error']];
        }

        $existingRuleId = null;
        $isExactlySame = false;
        foreach (($rulesetDetails['result']['rules'] ?? []) as $rule) {
            if (($rule['description'] ?? '') === $ruleDescription) {
                $existingRuleId = $rule['id'] ?? null;
                if (($rule['expression'] ?? '') === $expression
                    && ($rule['action'] ?? '') === 'set_cache_settings'
                    && ($rule['action_parameters']['cache'] ?? null) === false) {
                    $isExactlySame = true;
                }
                break;
            }
        }

        if ($isExactlySame && $existingRuleId) {
            return ['ok' => true, 'error' => null, 'action' => 'already_exists'];
        }

        if ($existingRuleId) {
            $update = $this->cloudflare->request('PATCH', '/zones/'.$zone.'/rulesets/'.$targetRulesetId.'/rules/'.$existingRuleId, [], $newRule);
            if (! $update['ok']) {
                return ['ok' => false, 'error' => 'Failed to update Cache Rule: '.$update['error']];
            }

            return ['ok' => true, 'error' => null, 'action' => 'updated'];
        }

        $add = $this->cloudflare->request('POST', '/zones/'.$zone.'/rulesets/'.$targetRulesetId.'/rules', [], $newRule);
        if (! $add['ok']) {
            return ['ok' => false, 'error' => 'Failed to add Cache Rule to existing ruleset: '.$add['error']];
        }

        return ['ok' => true, 'error' => null, 'action' => 'appended'];
    }

    public function ensureSaasFallbackBypassRules(): array
    {
        $zone = $this->config->saasZoneId();
        $host = $this->config->saasCnameTarget();
        if ($zone === null || $host === '') {
            return ['ok' => false, 'error' => 'SaaS zone ID or CNAME target is missing.'];
        }

        $rules = [
            [
                'ref' => 'verifysky_saas_skip_legacy_security_products',
                'description' => 'VerifySky SaaS fallback: skip legacy edge security products',
                'expression' => '(http.host eq "'.$host.'")',
                'action' => 'skip',
                'action_parameters' => [
                    'products' => ['zoneLockdown', 'uaBlock', 'bic', 'hot', 'securityLevel', 'rateLimit', 'waf'],
                ],
            ],
            [
                'ref' => 'verifysky_saas_skip_later_security_phases',
                'description' => 'VerifySky SaaS fallback: skip later edge security phases',
                'expression' => '(http.host eq "'.$host.'")',
                'action' => 'skip',
                'action_parameters' => [
                    'phases' => ['http_ratelimit', 'http_request_firewall_managed', 'http_request_sbfm'],
                ],
            ],
        ];

        $entrypointPath = '/zones/'.$zone.'/rulesets/phases/http_request_firewall_custom/entrypoint';
        $entrypoint = $this->cloudflare->request('GET', $entrypointPath);

        if (! $entrypoint['ok'] && str_contains((string) $entrypoint['error'], '10003')) {
            $create = $this->cloudflare->request('POST', '/zones/'.$zone.'/rulesets', [], [
                'name' => 'default',
                'description' => 'VerifySky SaaS edge security bypass for fallback hostname',
                'kind' => 'zone',
                'phase' => 'http_request_firewall_custom',
                'rules' => $rules,
            ]);

            return ['ok' => $create['ok'], 'error' => $create['error'], 'action' => $create['ok'] ? 'created' : null];
        }

        if (! $entrypoint['ok']) {
            return ['ok' => false, 'error' => $entrypoint['error'], 'action' => null];
        }

        $current = is_array($entrypoint['result']) ? $entrypoint['result'] : [];
        $currentRules = is_array($current['rules'] ?? null) ? $current['rules'] : [];
        $existingRefs = [];
        foreach ($currentRules as $rule) {
            if (is_array($rule) && is_string($rule['ref'] ?? null)) {
                $existingRefs[$rule['ref']] = true;
            }
        }

        $added = 0;
        foreach (array_reverse($rules) as $rule) {
            if (! isset($existingRefs[$rule['ref']])) {
                array_unshift($currentRules, $rule);
                $added++;
            }
        }

        if ($added === 0) {
            return ['ok' => true, 'error' => null, 'action' => 'already_exists'];
        }

        $update = $this->cloudflare->request('PUT', $entrypointPath, [], [
            'name' => (string) ($current['name'] ?? 'default'),
            'description' => (string) ($current['description'] ?? 'VerifySky SaaS edge security bypass for fallback hostname'),
            'kind' => 'zone',
            'phase' => 'http_request_firewall_custom',
            'rules' => $currentRules,
        ]);

        return ['ok' => $update['ok'], 'error' => $update['error'], 'action' => $update['ok'] ? 'updated' : null];
    }

    public function ensureSaasBotManagementSettings(): array
    {
        $zone = $this->config->saasZoneId();
        if ($zone === null) {
            return ['ok' => false, 'error' => 'SaaS zone ID is missing.', 'action' => null];
        }

        $current = $this->cloudflare->request('GET', '/zones/'.$zone.'/bot_management');
        if (! $current['ok']) {
            return ['ok' => false, 'error' => $current['error'], 'action' => null];
        }

        $settings = is_array($current['result']) ? $current['result'] : [];
        $desired = $settings;
        $desired['enable_js'] = false;
        $desired['fight_mode'] = false;

        if (($settings['enable_js'] ?? null) === false && ($settings['fight_mode'] ?? null) === false) {
            return ['ok' => true, 'error' => null, 'action' => 'already_disabled'];
        }

        unset($desired['using_latest_model']);
        $update = $this->cloudflare->request('PUT', '/zones/'.$zone.'/bot_management', [], $desired);

        return ['ok' => $update['ok'], 'error' => $update['error'], 'action' => $update['ok'] ? 'disabled_fight_mode' : null];
    }
}
