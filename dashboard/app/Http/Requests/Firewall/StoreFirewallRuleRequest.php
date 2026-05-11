<?php

namespace App\Http\Requests\Firewall;

use App\Services\Plans\PlanLimitsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class StoreFirewallRuleRequest extends FormRequest
{
    private ?string $authorizationMessage = null;

    public function authorize(): bool
    {
        $domain = (string) $this->input('domain_name', '');
        $tenantId = trim((string) session('current_tenant_id', ''));
        if (strtolower(trim($domain)) === 'global' && $tenantId !== '') {
            return true;
        }

        $limits = app(PlanLimitsService::class);
        $allowed = $limits->domainBelongsToTenant(
            $domain,
            $tenantId,
            (bool) session('is_admin')
        );

        if (! $allowed) {
            $this->authorizationMessage = strtolower(trim($domain)) === 'global'
                ? 'Only platform admins can create rules for all domains.'
                : 'You do not have access to manage firewall rules for this domain.';
        }

        return $allowed;
    }

    public function rules(): array
    {
        return [
            'domain_name' => ['required', 'string'],
            'description' => ['nullable', 'string', 'max:255'],
            'action' => ['required', 'in:block,challenge,managed_challenge,js_challenge,allow,block_ip_farm'],
            'field' => ['required', 'string', 'in:ip.src,ip.src.country,ip.src.asnum,http.request.uri.path,http.request.method,http.user_agent,client.device_type'],
            'operator' => ['required', 'string', 'in:eq,ne,in,not_in,contains,not_contains,starts_with'],
            'value' => ['required', 'string', 'max:3000'],
            'duration' => ['nullable', 'string', 'in:forever,1h,6h,24h,7d,30d'],
            'paused' => ['nullable', 'in:0,1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $limits = app(PlanLimitsService::class);
            $usage = $limits->getFirewallRulesUsage(session('current_tenant_id'), (bool) session('is_admin'));
            if (! ($usage['can_add'] ?? false)) {
                $validator->errors()->add('domain_name', (string) ($usage['message'] ?? 'Firewall rule limit reached for this plan.'));
            }
        });
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException($this->authorizationMessage ?? 'This action is unauthorized.');
    }
}
