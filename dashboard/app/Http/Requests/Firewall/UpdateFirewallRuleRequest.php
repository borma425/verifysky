<?php

namespace App\Http\Requests\Firewall;

use App\Services\Plans\PlanLimitsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFirewallRuleRequest extends FormRequest
{
    private ?string $authorizationMessage = null;

    public function authorize(): bool
    {
        $domain = (string) $this->route('domain');
        $tenantId = trim((string) session('current_tenant_id', ''));
        $ruleId = (int) $this->route('ruleId');

        $limits = app(PlanLimitsService::class);
        $isAdmin = (bool) session('is_admin');
        $isGlobal = strtolower(trim($domain)) === 'global';
        $allowedDomain = $isGlobal && ! $isAdmin
            ? $tenantId !== ''
            : $limits->domainBelongsToTenant($domain, $tenantId, $isAdmin);
        $allowedRule = $limits->canManageRuleIds([$ruleId], $tenantId, $isAdmin);

        $allowed = $allowedDomain && $allowedRule;

        if (! $allowed) {
            $this->authorizationMessage = 'You do not have access to update firewall rules for this domain.';
        }

        return $allowed;
    }

    public function rules(): array
    {
        return [
            'description' => ['nullable', 'string', 'max:255'],
            'action' => ['required', 'in:block,challenge,managed_challenge,js_challenge,allow,block_ip_farm'],
            'field' => ['required', 'string', 'in:ip.src,ip.src.country,ip.src.asnum,http.request.uri.path,http.request.method,http.user_agent'],
            'operator' => ['required', 'string', 'in:eq,ne,in,not_in,contains,not_contains,starts_with'],
            'value' => ['required', 'string', 'max:3000'],
            'duration' => ['nullable', 'string', 'in:forever,1h,6h,24h,7d,30d'],
            'paused' => ['nullable', 'in:0,1'],
            'preserve_expiry' => ['nullable', 'in:1'],
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException($this->authorizationMessage ?? 'This action is unauthorized.');
    }
}
