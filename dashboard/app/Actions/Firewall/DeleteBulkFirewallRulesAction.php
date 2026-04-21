<?php

namespace App\Actions\Firewall;

use App\Services\EdgeShieldService;

class DeleteBulkFirewallRulesAction
{
    public function __construct(private readonly EdgeShieldService $edgeShield) {}

    public function execute(array $ruleIds): array
    {
        foreach ($ruleIds as $id) {
            $ruleRes = $this->edgeShield->getCustomFirewallRuleByIdGlobal((int) $id);
            if ($ruleRes['ok'] && ! empty($ruleRes['rule'])) {
                $rule = $ruleRes['rule'];
                $this->edgeShield->syncKvForFirewallRuleAction(
                    (string) ($rule['domain_name'] ?? ''),
                    (string) ($rule['expression_json'] ?? ''),
                    (string) ($rule['action'] ?? '')
                );
            }
        }

        return $this->edgeShield->deleteBulkCustomFirewallRules($ruleIds);
    }
}
