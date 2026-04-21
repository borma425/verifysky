<?php

namespace App\Actions\Firewall;

use App\Services\EdgeShieldService;

class DeleteFirewallRuleAction
{
    public function __construct(private readonly EdgeShieldService $edgeShield) {}

    public function execute(string $domain, int $ruleId): array
    {
        $ruleRes = $this->edgeShield->getCustomFirewallRuleById($domain, $ruleId);
        if ($ruleRes['ok'] && ! empty($ruleRes['rule'])) {
            $rule = $ruleRes['rule'];
            $this->edgeShield->syncKvForFirewallRuleAction(
                $domain,
                (string) ($rule['expression_json'] ?? ''),
                (string) ($rule['action'] ?? '')
            );
        }

        return $this->edgeShield->deleteCustomFirewallRule($domain, $ruleId);
    }
}
