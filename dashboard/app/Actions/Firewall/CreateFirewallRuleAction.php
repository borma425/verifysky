<?php

namespace App\Actions\Firewall;

use App\Services\EdgeShieldService;

class CreateFirewallRuleAction
{
    public function __construct(private readonly EdgeShieldService $edgeShield) {}

    public function execute(array $validated): array
    {
        $domain = $validated['domain_name'];
        $tenantId = isset($validated['tenant_id']) ? trim((string) $validated['tenant_id']) : null;
        $scope = isset($validated['scope']) ? (string) $validated['scope'] : ($domain === 'global' ? 'platform' : 'domain');
        $expressionJson = (string) json_encode([
            'field' => $validated['field'],
            'operator' => $validated['operator'],
            'value' => $validated['value'],
        ]);
        $expiresAt = $this->resolveExpiry($validated['duration'] ?? null);

        if ($validated['action'] === 'allow' && $validated['field'] === 'ip.src') {
            $ipRaw = trim(strtolower($validated['value']));
            if (! str_contains($ipRaw, ',') && ! str_contains($ipRaw, '/')) {
                $this->edgeShield->queryD1("DELETE FROM security_logs WHERE ip_address = '".str_replace("'", "''", $ipRaw)."'");
                $this->edgeShield->queryD1("DELETE FROM ip_access_rules WHERE ip_or_cidr = '".str_replace("'", "''", $ipRaw)."'");
            }

            $farmIps = $this->edgeShield->findIpsInFarm($validated['value'], 'ip.src', $tenantId);
            if (! empty($farmIps)) {
                $removal = $this->edgeShield->removeIpsFromFarm($farmIps, $tenantId);
                $create = $this->edgeShield->createCustomFirewallRule(
                    $domain,
                    $validated['description'] ?? '',
                    $validated['action'],
                    $expressionJson,
                    ((int) ($validated['paused'] ?? 0)) === 1,
                    $expiresAt,
                    $tenantId,
                    $scope
                );

                $removedCount = $removal['removed'] ?? 0;
                $message = $removedCount > 0 ? " Also removed {$removedCount} IP(s) from the IP Farm graveyard." : '';

                return ['ok' => $create['ok'], 'error' => $create['error'] ?? null, 'message' => $message];
            }
        }

        if ($validated['action'] === 'block' && $validated['field'] === 'ip.src') {
            $farmIps = $this->edgeShield->findIpsInFarm($validated['value'], 'ip.src', $tenantId);
            if (! empty($farmIps)) {
                $ipList = implode(', ', array_slice($farmIps, 0, 5));
                $extra = count($farmIps) > 5 ? ' (+'.(count($farmIps) - 5).' more)' : '';

                return ['ok' => false, 'error' => "These IPs are already permanently banned in the IP Farm: {$ipList}{$extra}. No need to create a duplicate block rule."];
            }
        }

        $finalAction = $validated['action'];
        $finalDescription = $validated['description'] ?? '';
        if ($finalAction === 'block_ip_farm') {
            if ($validated['field'] !== 'ip.src') {
                return ['ok' => false, 'error' => 'The "block to ip farm" action can only be used when Field is set to "IP Address / CIDR".'];
            }
            $finalAction = 'block';
            $expiresAt = null;
            if (! str_starts_with($finalDescription, '[IP-FARM]')) {
                $finalDescription = trim('[IP-FARM] '.$finalDescription);
            }
        }

        $create = $this->edgeShield->createCustomFirewallRule(
            $domain,
            $finalDescription,
            $finalAction,
            $expressionJson,
            ((int) ($validated['paused'] ?? 0)) === 1,
            $expiresAt,
            $tenantId,
            $scope
        );

        return ['ok' => $create['ok'], 'error' => $create['error'] ?? null, 'message' => null];
    }

    private function resolveExpiry(?string $duration): ?int
    {
        if (! $duration || $duration === 'forever') {
            return null;
        }
        $seconds = match ($duration) {
            '1h' => 3600,
            '6h' => 21600,
            '24h' => 86400,
            '7d' => 604800,
            '30d' => 2592000,
            default => 0,
        };

        return $seconds > 0 ? time() + $seconds : null;
    }
}
