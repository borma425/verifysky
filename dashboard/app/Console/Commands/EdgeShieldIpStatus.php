<?php

namespace App\Console\Commands;

use App\Services\EdgeShield\CloudflareApiClient;
use App\Services\EdgeShieldService;
use Illuminate\Console\Command;

class EdgeShieldIpStatus extends Command
{
    protected $signature = 'edgeshield:ip-status {ip} {--domain=}';

    protected $description = 'Read-only status report for an IP across Worker KV, D1 firewall rules, logs, and Cloudflare rulesets.';

    public function handle(EdgeShieldService $edgeShield, CloudflareApiClient $cloudflare): int
    {
        $ip = trim((string) $this->argument('ip'));
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->error('Invalid IP address.');

            return self::FAILURE;
        }

        $domainFilter = strtolower(trim((string) $this->option('domain')));
        $domainsResult = $edgeShield->listDomains(null, true);
        if (! ($domainsResult['ok'] ?? false)) {
            $this->error((string) ($domainsResult['error'] ?? 'Failed to list domains.'));

            return self::FAILURE;
        }

        $domains = array_values(array_filter(
            $domainsResult['domains'] ?? [],
            static fn (array $row): bool => $domainFilter === ''
                || strtolower((string) ($row['domain_name'] ?? '')) === $domainFilter
        ));

        $this->info('Worker runtime status');
        foreach ($domains as $row) {
            $domain = (string) ($row['domain_name'] ?? '');
            if ($domain === '') {
                continue;
            }
            $status = $edgeShield->getIpAdminStatusViaWorkerAdmin($domain, $ip);
            $this->line($domain.': '.json_encode($status, JSON_UNESCAPED_SLASHES));
        }

        $this->info('D1 custom firewall matches');
        $this->dumpD1Rows($edgeShield, sprintf(
            "SELECT id, domain_name, tenant_id, scope, description, action, expression_json, paused
             FROM custom_firewall_rules
             WHERE expression_json LIKE '%%%s%%'
             ORDER BY id DESC LIMIT 50",
            $this->escapeSqlLike($ip)
        ));

        $this->info('Recent security logs');
        $this->dumpD1Rows($edgeShield, sprintf(
            "SELECT domain_name, event_type, risk_score, details, created_at
             FROM security_logs
             WHERE ip_address = '%s'
             ORDER BY id DESC LIMIT 20",
            str_replace("'", "''", $ip)
        ));

        $this->info('Cloudflare custom ruleset matches');
        $zones = [];
        foreach ($domains as $row) {
            $zone = trim((string) ($row['zone_id'] ?? ''));
            if ($zone !== '') {
                $zones[$zone] = true;
            }
        }
        foreach (array_keys($zones) as $zoneId) {
            $rulesets = $cloudflare->request('GET', '/zones/'.$zoneId.'/rulesets', ['phase' => 'http_request_firewall_custom']);
            $matches = [];
            foreach (($rulesets['result'] ?? []) as $ruleset) {
                $details = $cloudflare->request('GET', '/zones/'.$zoneId.'/rulesets/'.($ruleset['id'] ?? ''));
                $payload = json_encode($details['result'] ?? [], JSON_UNESCAPED_SLASHES);
                if (is_string($payload) && str_contains($payload, $ip)) {
                    $matches[] = $details['result'] ?? [];
                }
            }
            $this->line($zoneId.': '.json_encode($matches, JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    private function dumpD1Rows(EdgeShieldService $edgeShield, string $sql): void
    {
        $result = $edgeShield->queryD1($sql);
        if (! ($result['ok'] ?? false)) {
            $this->line(json_encode(['ok' => false, 'error' => $result['error'] ?? 'query failed']));

            return;
        }

        $rows = $edgeShield->parseWranglerJson((string) ($result['output'] ?? ''))[0]['results'] ?? [];
        $this->line(json_encode($rows, JSON_UNESCAPED_SLASHES));
    }

    private function escapeSqlLike(string $value): string
    {
        return str_replace(["'", '%', '_'], ["''", '\\%', '\\_'], $value);
    }
}
