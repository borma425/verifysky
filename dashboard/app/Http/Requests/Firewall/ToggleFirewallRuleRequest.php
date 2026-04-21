<?php

namespace App\Http\Requests\Firewall;

use App\Services\Plans\PlanLimitsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class ToggleFirewallRuleRequest extends FormRequest
{
    private ?string $authorizationMessage = null;

    public function authorize(): bool
    {
        $limits = app(PlanLimitsService::class);
        $allowed = $limits->domainBelongsToTenant(
            (string) $this->route('domain'),
            session('current_tenant_id'),
            (bool) session('is_admin')
        );

        if (! $allowed) {
            $this->authorizationMessage = 'You do not have access to change firewall rules for this domain.';
        }

        return $allowed;
    }

    public function rules(): array
    {
        return [
            'paused' => ['required', 'in:0,1'],
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException($this->authorizationMessage ?? 'This action is unauthorized.');
    }
}
