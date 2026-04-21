<?php

namespace App\Http\Requests\Firewall;

use App\Services\Plans\PlanLimitsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BulkDestroyFirewallRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rule_ids' => ['required', 'array'],
            'rule_ids.*' => ['integer'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $limits = app(PlanLimitsService::class);
            if (! $limits->canManageRuleIds(
                (array) $this->input('rule_ids', []),
                session('current_tenant_id'),
                (bool) session('is_admin')
            )) {
                $validator->errors()->add('rule_ids', 'You can only delete firewall rules that belong to your own domains.');
            }
        });
    }
}
