<?php

namespace App\Actions\Logs;

use App\Repositories\SecurityLogRepository;
use App\Services\EdgeShieldService;

class AllowIpFromLogsAction
{
    public function __construct(
        private readonly EdgeShieldService $edgeShield,
        private readonly SecurityLogRepository $logs
    ) {}

    public function execute(string $ip, string $domain): array
    {
        if (trim($domain) === '' || trim($domain) === '-') {
            return ['ok' => false, 'error' => 'Cannot allow this IP because domain is missing for this log row.'];
        }
        $tenantId = (bool) session('is_admin') ? null : trim((string) session('current_tenant_id', ''));
        $scope = $tenantId ? 'domain' : 'platform';

        $result = $this->edgeShield->allowIpViaWorkerAdmin($domain, $ip, 24, 'dashboard security logs allow');
        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string) ($result['error'] ?? 'Failed to allow IP via worker admin.')];
        }

        $status = $this->edgeShield->getIpAdminStatusViaWorkerAdmin($domain, $ip);
        if (! ($status['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'IP was allow-listed, but status verification failed: '.((string) ($status['error'] ?? 'unknown error'))];
        }

        $isAllowed = (bool) (($status['status']['allowed'] ?? false));
        $isBanned = (bool) (($status['status']['banned'] ?? false));
        if (! $isAllowed || $isBanned) {
            return ['ok' => false, 'error' => 'Allow action did not stabilize as expected (allowed=true, banned=false).'];
        }

        $deleteResult = $this->logs->deleteLogsByIp($ip, $domain);
        $this->logs->deleteIpAccessRulesByIp($ip);
        $this->edgeShield->removeIpsFromFarm([$ip], $tenantId ?: null);
        $domainsResult = $this->edgeShield->listDomains($tenantId ?: null, (bool) session('is_admin'));
        if (($domainsResult['ok'] ?? false) === true) {
            foreach (($domainsResult['domains'] ?? []) as $row) {
                $hostname = (string) ($row['domain_name'] ?? '');
                if ($hostname !== '') {
                    $this->edgeShield->cleanupIpViaWorkerAdmin($hostname, $ip);
                }
            }
        }
        $this->logs->deleteCustomFirewallRulesByIp($ip, true, $tenantId ?: null);
        $this->edgeShield->createIpAccessRule($domain, $ip, 'allow', 'Manually allow-listed from security logs page');
        $this->edgeShield->createCustomFirewallRule(
            $domain,
            "Allow IP: $ip (From Logs)",
            'allow',
            json_encode(['field' => 'ip.src', 'operator' => 'eq', 'value' => $ip]) ?: '{}',
            false,
            null,
            $tenantId ?: null,
            $scope
        );
        $this->edgeShield->purgeIpRulesCache($domain);
        $this->edgeShield->purgeCustomFirewallRulesCache($domain);
        $this->edgeShield->purgeCustomFirewallRulesCache('global');

        if (! ($deleteResult['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'IP was allow-listed, but failed to reset visit counters: '.((string) ($deleteResult['error'] ?? 'unknown error'))];
        }

        $this->logs->bumpCacheVersion();

        return ['ok' => true, 'message' => 'IP '.$ip.' was allow-listed, unbanned, and added to Manual Firewall Rules.'];
    }
}
