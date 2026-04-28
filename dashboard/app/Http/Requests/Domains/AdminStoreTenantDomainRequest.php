<?php

namespace App\Http\Requests\Domains;

use App\Models\Tenant;
use App\Services\Plans\PlanLimitsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AdminStoreTenantDomainRequest extends FormRequest
{
    public const DOMAIN_LIMIT_MESSAGE = 'عفواً، لقد وصل هذا المستخدم للحد الأقصى لخطته. يرجى ترقية الخطة أو إضافة مساحة إضافية أولاً';

    public function authorize(): bool
    {
        return (bool) session('is_admin');
    }

    public function rules(): array
    {
        return [
            'domain_name' => ['required', 'string', 'max:255'],
            'origin_server' => ['nullable', 'string', 'max:255'],
            'security_mode' => ['nullable', 'in:monitor,balanced,aggressive'],
            'apex_mode' => ['nullable', 'in:www_redirect,direct_apex,subdomain_only'],
            'dns_provider' => ['nullable', 'in:cloudflare,namecheap,godaddy,spaceship,other'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $tenant = $this->route('tenant');
            if (! $tenant instanceof Tenant) {
                return;
            }

            $usage = app(PlanLimitsService::class)->getDomainsUsage($tenant);
            if (! (bool) ($usage['can_add'] ?? false)) {
                $validator->errors()->add('domain_name', self::DOMAIN_LIMIT_MESSAGE);
            }
        });
    }
}
