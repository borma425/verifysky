<?php

namespace App\Services\Plans;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\Billing\EffectiveTenantPlanService;
use App\Services\EdgeShield\D1DatabaseClient;
use Illuminate\Support\Facades\Schema;

class PlanLimitsService
{
    private const EXTRA_DOMAIN_ALLOWANCE_SETTING_KEYS = [
        'extra_domains',
        'bonus_domains',
        'extra_domain_slots',
        'bonus_domain_slots',
        'extra_domain_limit',
        'bonus_domain_limit',
    ];

    public function __construct(
        private readonly D1DatabaseClient $d1,
        private readonly ?EffectiveTenantPlanService $effectivePlans = null
    ) {}

    public function getFirewallRulesUsage(?string $tenantId, bool $isAdmin = false): array
    {
        $tenant = $this->resolveTenant($tenantId);
        $plan = $this->planDefinitionForTenant($tenant);
        $limit = (int) ($plan['limits']['custom_rules'] ?? 0);
        $used = $isAdmin ? 0 : $this->countManualFirewallRulesForTenant($tenantId);
        $remaining = $isAdmin ? null : max(0, $limit - $used);
        $canAdd = $isAdmin || $used < $limit;

        return [
            'used' => $used,
            'limit' => $isAdmin ? null : $limit,
            'remaining' => $remaining,
            'can_add' => $canAdd,
            'plan_key' => $plan['key'],
            'plan_name' => $plan['name'],
            'price_monthly' => $plan['price_monthly'],
            'upgrade_to' => $plan['upgrade_to'],
            'message' => $canAdd ? null : $this->firewallLimitMessage($plan),
        ];
    }

    public function canAddFirewallRule(?string $tenantId, bool $isAdmin = false): bool
    {
        return (bool) ($this->getFirewallRulesUsage($tenantId, $isAdmin)['can_add'] ?? false);
    }

    /**
     * @return array{used:int,limit:?int,remaining:?int,can_add:bool,plan_key:string,plan_name:string,price_monthly:int,upgrade_to:mixed,message:?string}
     */
    public function getDomainsUsage(Tenant $tenant): array
    {
        $plan = $this->planDefinitionForTenant($tenant);
        $rawLimit = $plan['limits']['domains'] ?? null;
        $includedLimit = is_numeric($rawLimit) ? max(0, (int) $rawLimit) : null;
        $extraAllowance = $this->extraDomainAllowance($tenant);
        $limit = $includedLimit === null ? null : $includedLimit + $extraAllowance;
        $used = $this->countBillableDomains((string) $tenant->getKey());
        $remaining = $limit === null ? null : max(0, $limit - $used);
        $canAdd = $limit === null || $used < $limit;

        return [
            'used' => $used,
            'limit' => $limit,
            'included_limit' => $includedLimit,
            'extra_allowance' => $extraAllowance,
            'remaining' => $remaining,
            'can_add' => $canAdd,
            'plan_key' => $plan['key'],
            'plan_name' => $plan['name'],
            'price_monthly' => $plan['price_monthly'],
            'upgrade_to' => $plan['upgrade_to'],
            'message' => $canAdd ? null : $this->domainLimitMessage($plan),
        ];
    }

    public function canAddDomain(Tenant $tenant): bool
    {
        return $this->getDomainsUsage($tenant)['can_add'];
    }

    /**
     * @return array{protected_sessions:int,bot_fair_use:int,plan_key:string,plan_name:string}
     */
    public function getBillingUsageLimits(Tenant $tenant): array
    {
        $plan = $this->planDefinitionForTenant($tenant);

        return [
            'protected_sessions' => max(0, (int) ($plan['limits']['protected_sessions'] ?? 0)),
            'bot_fair_use' => max(0, (int) ($plan['limits']['bot_fair_use'] ?? 0)),
            'plan_key' => $plan['key'],
            'plan_name' => $plan['name'],
        ];
    }

    public function domainBelongsToTenant(string $domain, ?string $tenantId, bool $isAdmin = false): bool
    {
        $normalized = $this->normalizeDomain($domain);
        if ($normalized === '') {
            return false;
        }

        if ($normalized === 'global') {
            return $isAdmin;
        }

        if ($isAdmin) {
            return true;
        }

        if ($tenantId === null || trim($tenantId) === '') {
            return false;
        }

        return $this->tenantDomainsQuery($tenantId)
            ->where('hostname', $normalized)
            ->exists();
    }

    public function canManageRuleIds(array $ruleIds, ?string $tenantId, bool $isAdmin = false): bool
    {
        $ids = array_values(array_filter(array_map('intval', $ruleIds), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return true;
        }

        if ($isAdmin) {
            return true;
        }

        if ($tenantId === null || trim($tenantId) === '') {
            return false;
        }

        $sql = sprintf(
            'SELECT id, domain_name, tenant_id FROM custom_firewall_rules WHERE id IN (%s)',
            implode(',', $ids)
        );
        $result = $this->d1->query($sql);
        if (! ($result['ok'] ?? false)) {
            return false;
        }

        $rows = $this->d1->parseWranglerJson((string) ($result['output'] ?? ''))[0]['results'] ?? [];
        if (count($rows) !== count($ids)) {
            return false;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                return false;
            }
            if ((string) ($row['tenant_id'] ?? '') === (string) $tenantId) {
                continue;
            }
            if (! $this->domainBelongsToTenant((string) ($row['domain_name'] ?? ''), $tenantId, false)) {
                return false;
            }
        }

        return true;
    }

    public function managedDomainNames(?string $tenantId, bool $isAdmin = false): array
    {
        if ($isAdmin) {
            return [];
        }

        if ($tenantId === null || trim($tenantId) === '') {
            return [];
        }

        return $this->tenantDomainsQuery($tenantId)
            ->pluck('hostname')
            ->map(fn (string $hostname): string => $this->normalizeDomain($hostname))
            ->filter()
            ->values()
            ->all();
    }

    public function firewallLimitMessage(array $plan): string
    {
        $limit = (int) ($plan['limits']['custom_rules'] ?? 0);
        $upgradeKey = $plan['upgrade_to'] ?? null;
        $upgradeName = is_string($upgradeKey) ? (string) (config("plans.plans.{$upgradeKey}.name") ?? ucfirst($upgradeKey)) : null;

        $message = sprintf(
            '%s includes up to %d custom firewall rules.',
            (string) ($plan['name'] ?? 'This plan'),
            $limit
        );

        if ($upgradeName) {
            $message .= ' Upgrade to '.$upgradeName.' to add more.';
        }

        return $message;
    }

    public function domainLimitMessage(array $plan): string
    {
        $upgradeKey = $plan['upgrade_to'] ?? null;
        $upgradeName = is_string($upgradeKey) ? (string) (config("plans.plans.{$upgradeKey}.name") ?? ucfirst($upgradeKey)) : null;
        $message = 'You have reached the maximum number of domains for your current plan.';

        if ($upgradeName) {
            $message .= ' Upgrade to '.$upgradeName.' to add more domains.';
        }

        return $message;
    }

    public function planDefinitionForTenant(?Tenant $tenant): array
    {
        if (! $tenant instanceof Tenant) {
            return $this->effectivePlans()->planDefinitionForKey((string) config('plans.default', 'starter'));
        }

        return $this->effectivePlans()->effectivePlanForTenant($tenant);
    }

    private function effectivePlans(): EffectiveTenantPlanService
    {
        return $this->effectivePlans ?? app(EffectiveTenantPlanService::class);
    }

    private function resolveTenant(?string $tenantId): ?Tenant
    {
        $normalized = trim((string) $tenantId);
        if ($normalized === '') {
            return null;
        }

        return Tenant::query()->find($normalized);
    }

    private function countManualFirewallRulesForTenant(?string $tenantId): int
    {
        $domains = $this->managedDomainNames($tenantId, false);
        if ($domains === []) {
            return 0;
        }

        $quotedDomains = implode(', ', array_map(
            static fn (string $domain): string => "'".str_replace("'", "''", $domain)."'",
            $domains
        ));

        $safeTenantId = str_replace("'", "''", trim((string) $tenantId));
        $sql = "SELECT COUNT(*) AS total
                FROM custom_firewall_rules
                WHERE (domain_name IN ({$quotedDomains}) OR tenant_id = '{$safeTenantId}')
                  AND (
                        description IS NULL
                        OR (
                            description NOT LIKE '[AI-DEFENSE]%'
                            AND description NOT LIKE '[IP-FARM]%'
                        )
                  )";

        $result = $this->d1->query($sql);
        if (! ($result['ok'] ?? false)) {
            return 0;
        }

        $rows = $this->d1->parseWranglerJson((string) ($result['output'] ?? ''))[0]['results'] ?? [];

        return (int) ($rows[0]['total'] ?? 0);
    }

    private function tenantDomainsQuery(?string $tenantId)
    {
        return TenantDomain::query()->where('tenant_id', trim((string) $tenantId));
    }

    private function countBillableDomains(string $tenantId): int
    {
        $query = $this->tenantDomainsQuery($tenantId);

        if (Schema::hasColumn('tenant_domains', 'provisioning_status')) {
            $query->whereIn('provisioning_status', [
                TenantDomain::PROVISIONING_PENDING,
                TenantDomain::PROVISIONING_PROVISIONING,
                TenantDomain::PROVISIONING_ACTIVE,
            ]);
        }

        return $query->count();
    }

    private function extraDomainAllowance(Tenant $tenant): int
    {
        $settings = $tenant->settings;
        if (! is_array($settings)) {
            return 0;
        }

        $allowance = 0;
        foreach (self::EXTRA_DOMAIN_ALLOWANCE_SETTING_KEYS as $key) {
            $value = $settings[$key] ?? null;
            if (is_numeric($value)) {
                $allowance += max(0, (int) $value);
            }
        }

        return $allowance;
    }

    private function normalizeDomain(string $domain): string
    {
        return strtolower(trim($domain));
    }
}
