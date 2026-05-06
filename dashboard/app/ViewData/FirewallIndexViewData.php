<?php

namespace App\ViewData;

use Carbon\Carbon;

class FirewallIndexViewData
{
    public function __construct(
        private readonly array $domains,
        private readonly array $rules,
        private readonly array $loadErrors,
        private readonly int $currentPage,
        private readonly int $totalPages,
        private readonly int $totalRules,
        private readonly array $firewallUsage,
        private readonly bool $canManageFirewallRules,
        private readonly bool $isAdmin
    ) {}

    public function toArray(): array
    {
        $preparedRules = array_map(fn (array $rule): array => $this->prepareRule($rule), $this->rules);
        $aiRules = array_values(array_filter($preparedRules, fn (array $rule): bool => $rule['is_ai']));
        $manualRules = array_values(array_filter($preparedRules, fn (array $rule): bool => ! $rule['is_ai']));

        return [
            'domains' => $this->domains,
            'firewallRules' => $preparedRules,
            'aiRules' => $aiRules,
            'manualRules' => $manualRules,
            'loadErrors' => $this->loadErrors,
            'currentPage' => $this->currentPage,
            'totalPages' => $this->totalPages,
            'totalRules' => $this->totalRules,
            'firewallUsage' => $this->firewallUsage,
            'canManageFirewallRules' => $this->canManageFirewallRules,
            'canAddFirewallRule' => (bool) ($this->firewallUsage['can_add'] ?? false),
            'showGlobalFirewallOption' => $this->isAdmin || $this->domains !== [],
            'globalFirewallLabel' => 'All domains',
        ];
    }

    private function prepareRule(array $rule): array
    {
        $expr = json_decode((string) ($rule['expression_json'] ?? '{}'), true);
        $value = (string) ($expr['value'] ?? 'unknown');
        $parts = array_map('trim', explode(',', $value));
        $valueDisplay = count($parts) > 3
            ? implode(', ', array_slice($parts, 0, 3)).' ... (+'.(count($parts) - 3).' more targets)'
            : $value;

        $expiresAt = is_numeric($rule['expires_at'] ?? null) ? (int) $rule['expires_at'] : null;
        $isExpired = $expiresAt !== null && $expiresAt < time();
        $isPaused = (bool) ($rule['paused'] ?? false);
        $isAi = str_starts_with((string) ($rule['description'] ?? ''), '[AI-DEFENSE]');

        return [
            'id' => (int) ($rule['id'] ?? 0),
            'domain_name' => (string) ($rule['domain_name'] ?? 'unknown'),
            'description' => (string) ($rule['description'] ?? 'No description'),
            'description_display' => $isAi
                ? str_replace('[AI-DEFENSE] ', '', (string) ($rule['description'] ?? 'No description'))
                : (string) ($rule['description'] ?? 'No description'),
            'action' => (string) ($rule['action'] ?? ''),
            'field' => (string) ($expr['field'] ?? 'unknown'),
            'operator' => (string) ($expr['operator'] ?? 'unknown'),
            'value' => $value,
            'value_display' => $valueDisplay,
            'expires_at' => $expiresAt,
            'expires_human' => $expiresAt ? Carbon::createFromTimestamp($expiresAt)->diffForHumans() : null,
            'expires_utc' => $expiresAt ? gmdate('Y-m-d H:i', $expiresAt).' UTC' : null,
            'is_expired' => $isExpired,
            'is_paused' => $isPaused,
            'next_paused_value' => $isPaused ? 0 : 1,
            'status_label' => $isExpired ? 'Expired' : ($isPaused ? 'Paused' : ($isAi ? 'Active Defense' : 'Enabled')),
            'status_class' => $isExpired
                ? 'border-rose-400/35 bg-rose-500/20 text-rose-100'
                : ($isPaused
                    ? 'border-amber-400/35 bg-amber-500/20 text-amber-100'
                    : ($isAi ? 'border-indigo-400/35 bg-indigo-500/20 text-indigo-100' : 'border-emerald-400/35 bg-emerald-500/20 text-emerald-100')),
            'is_ai' => $isAi,
        ];
    }
}
