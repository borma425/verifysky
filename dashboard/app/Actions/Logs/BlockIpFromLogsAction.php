<?php

namespace App\Actions\Logs;

use App\Repositories\SecurityLogRepository;
use App\Services\EdgeShieldService;

class BlockIpFromLogsAction
{
    public function __construct(
        private readonly EdgeShieldService $edgeShield,
        private readonly SecurityLogRepository $logs
    ) {}

    public function execute(string $ip, string $domain): array
    {
        if (trim($domain) === '' || trim($domain) === '-') {
            return ['ok' => false, 'error' => 'Cannot block this IP because domain is missing for this log row.'];
        }
        $tenantId = (bool) session('is_admin') ? null : trim((string) session('current_tenant_id', ''));
        $scope = $tenantId ? 'domain' : 'platform';

        $result = $this->edgeShield->blockIpViaWorkerAdmin($domain, $ip, 24, 'dashboard security logs block');
        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => (string) ($result['error'] ?? 'Failed to block IP via worker admin.')];
        }

        $status = $this->edgeShield->getIpAdminStatusViaWorkerAdmin($domain, $ip);
        if (! ($status['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'IP was blocked, but status verification failed: '.((string) ($status['error'] ?? 'unknown error'))];
        }
        $isAllowed = (bool) (($status['status']['allowed'] ?? false));
        $isBanned = (bool) (($status['status']['banned'] ?? false));
        if ($isAllowed || ! $isBanned) {
            return ['ok' => false, 'error' => 'Block action did not stabilize as expected (allowed=false, banned=true).'];
        }

        $this->logs->deleteIpAccessRulesByIp($ip, $domain);
        $this->logs->deleteCustomFirewallRulesByIp($ip, false, $tenantId ?: null);
        $this->edgeShield->createIpAccessRule($domain, $ip, 'block', 'Manually blocked from security logs page');

        $farmIps = $this->edgeShield->findIpsInFarm($ip, 'ip.src', $tenantId ?: null);
        if (empty($farmIps)) {
            $this->edgeShield->createCustomFirewallRule(
                $domain,
                "Block IP: $ip (From Logs)",
                'block',
                json_encode(['field' => 'ip.src', 'operator' => 'eq', 'value' => $ip]) ?: '{}',
                false,
                null,
                $tenantId ?: null,
                $scope
            );
        }

        $this->logs->bumpCacheVersion();

        return [
            'ok' => true,
            'message' => 'IP '.$ip.' was blocked on '.$domain.' for up to 24 hours and added to manual firewall rules.'
                .(! empty($farmIps) ? ' (already in the blocked IP list)' : ''),
        ];
    }
}
