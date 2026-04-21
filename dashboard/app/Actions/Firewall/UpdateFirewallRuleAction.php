<?php

namespace App\Actions\Firewall;

use App\Services\EdgeShieldService;

class UpdateFirewallRuleAction
{
    public function __construct(private readonly EdgeShieldService $edgeShield) {}

    public function execute(string $domain, int $ruleId, array $validated): array
    {
        $tenantId = isset($validated['tenant_id']) ? trim((string) $validated['tenant_id']) : null;
        $scope = isset($validated['scope']) ? (string) $validated['scope'] : null;
        $expressionJson = (string) json_encode([
            'field' => $validated['field'],
            'operator' => $validated['operator'],
            'value' => $validated['value'],
        ]);
        $isPaused = ((int) ($validated['paused'] ?? 0)) === 1;

        $oldRuleRes = $this->edgeShield->getCustomFirewallRuleById($domain, $ruleId);
        if ($oldRuleRes['ok'] && ! empty($oldRuleRes['rule'])) {
            $oldRule = $oldRuleRes['rule'];
            $oldAction = (string) ($oldRule['action'] ?? '');
            $newAction = $validated['action'];
            if ($isPaused || $oldAction !== $newAction) {
                $this->edgeShield->syncKvForFirewallRuleAction(
                    $domain,
                    (string) ($oldRule['expression_json'] ?? ''),
                    $oldAction
                );
            }
        }

        $expiresAt = $this->resolveExpiry($validated['duration'] ?? null);
        if ($expiresAt === null && ! empty($validated['preserve_expiry']) && isset($oldRuleRes['rule']['expires_at'])) {
            $expiresAt = $oldRuleRes['rule']['expires_at'];
        }

        $finalAction = $validated['action'];
        $finalDescription = $validated['description'] ?? '';

        if ($finalAction === 'allow' && $validated['field'] === 'ip.src') {
            $ipRaw = trim(strtolower($validated['value']));
            if (! str_contains($ipRaw, ',') && ! str_contains($ipRaw, '/')) {
                $this->edgeShield->queryD1("DELETE FROM security_logs WHERE ip_address = '".str_replace("'", "''", $ipRaw)."'");
                $this->edgeShield->queryD1("DELETE FROM ip_access_rules WHERE ip_or_cidr = '".str_replace("'", "''", $ipRaw)."'");
            }
            $farmIps = $this->edgeShield->findIpsInFarm($validated['value'], 'ip.src', $tenantId);
            if (! empty($farmIps)) {
                $this->edgeShield->removeIpsFromFarm($farmIps, $tenantId);
            }
        }

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

        $update = $this->edgeShield->updateCustomFirewallRule(
            $domain,
            $ruleId,
            $finalDescription,
            $finalAction,
            $expressionJson,
            $isPaused,
            $expiresAt,
            $tenantId,
            $scope
        );

        return ['ok' => $update['ok'], 'error' => $update['error'] ?? null];
    }

    private function resolveExpiry(?string $duration): ?int
    {
        if (! $duration || $duration === 'forever') {
            return null;
        }
        $seconds = match ($duration) {
            '1m' => 60,
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
