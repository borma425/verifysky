<?php

namespace App\Http\Requests\Domains;

use App\Models\Tenant;
use App\Services\Plans\PlanLimitsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Throwable;

class StoreDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            if ($validator->errors()->isNotEmpty() || (bool) session('is_admin')) {
                return;
            }

            $tenantId = trim((string) session('current_tenant_id'));
            if ($tenantId === '') {
                return;
            }

            try {
                $tenant = Tenant::query()->find($tenantId);
            } catch (Throwable) {
                return;
            }

            if (! $tenant instanceof Tenant) {
                return;
            }

            $usage = app(PlanLimitsService::class)->getDomainsUsage($tenant);
            if (! $usage['can_add']) {
                $validator->errors()->add(
                    'domain_name',
                    (string) $usage['message']
                );
            }
        });
    }
}
