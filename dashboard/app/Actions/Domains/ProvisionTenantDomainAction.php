<?php

namespace App\Actions\Domains;

use App\Jobs\Domains\EnsureCloudflareWorkerRouteJob;
use App\Jobs\Domains\ProvisionCloudflareSaasHostnameJob;
use App\Jobs\Domains\SyncDomainConfigToD1Job;
use App\Jobs\Domains\SyncSaasSecurityArtifactsJob;
use App\Jobs\Domains\ValidateOriginServerJob;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\EdgeShieldService;
use App\Services\Plans\PlanLimitsService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProvisionTenantDomainAction
{
    public function __construct(
        private readonly EdgeShieldService $edgeShield,
        private readonly PlanLimitsService $planLimits
    ) {}

    public function execute(array $validated, ?string $tenantId): array
    {
        $tenantId = trim((string) $tenantId);
        if ($tenantId === '') {
            return ['ok' => false, 'error' => 'Tenant context is required to provision a domain.'];
        }

        if (! Schema::hasTable('tenant_domains')) {
            return ['ok' => false, 'error' => 'Domain storage is not ready. Run database migrations before onboarding domains.'];
        }

        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant instanceof Tenant) {
            return ['ok' => false, 'error' => 'Tenant was not found.'];
        }

        $securityMode = $validated['security_mode'] ?? 'balanced';
        $originServer = trim((string) ($validated['origin_server'] ?? ''));
        $originMode = $originServer === '' ? 'auto' : 'manual';
        $requestedDomain = (string) $validated['domain_name'];
        $hostnames = $this->edgeShield->saasHostnamesForInput($requestedDomain);

        if (count($hostnames) === 0) {
            return ['ok' => false, 'error' => 'Domain name is empty.'];
        }

        $created = [];
        $warnings = [];

        foreach ($hostnames as $hostname) {
            $hostname = strtolower(trim((string) $hostname));
            if ($hostname === '') {
                continue;
            }

            $existing = TenantDomain::query()->where('hostname', $hostname)->first();
            if ($existing instanceof TenantDomain && (string) $existing->tenant_id !== $tenantId) {
                return $created === []
                    ? ['ok' => false, 'error' => 'This hostname is already assigned to another tenant.']
                    : $this->partialResult($created, $originMode, $originServer, array_merge($warnings, [
                        'One hostname was skipped because it is already assigned to another tenant.',
                    ]));
            }

            if ($existing instanceof TenantDomain && $this->isActiveDuplicate($existing)) {
                return $created === []
                    ? ['ok' => false, 'error' => 'This hostname is already provisioned for this tenant.']
                    : $this->partialResult($created, $originMode, $originServer, array_merge($warnings, [
                        'One hostname was skipped because it is already provisioned for this tenant.',
                    ]));
            }

            if (! $this->hasReusableSlot($existing) && ! ($this->planLimits->getDomainsUsage($tenant)['can_add'] ?? false)) {
                return $created === []
                    ? ['ok' => false, 'error' => (string) ($this->planLimits->getDomainsUsage($tenant)['message'] ?? 'You have reached the maximum number of domains for your current plan.')]
                    : $this->partialResult($created, $originMode, $originServer, array_merge($warnings, [
                        'One hostname was skipped because this tenant has reached the domain limit.',
                    ]));
            }

            $domain = $this->upsertPendingTenantDomain($tenantId, $hostname, $originServer, (string) $securityMode, $existing);

            try {
                $this->dispatchProvisioningChain($domain, $requestedDomain, $originServer);
                $created[] = $hostname;
            } catch (Throwable $exception) {
                $domain->forceFill([
                    'provisioning_status' => TenantDomain::PROVISIONING_FAILED,
                    'provisioning_error' => 'VerifySky could not queue domain provisioning. Please retry.',
                    'provisioning_finished_at' => now(),
                ])->save();

                $warnings[] = 'Domain '.$hostname.' was saved locally, but provisioning could not be queued yet.';
            }
        }

        if ($warnings !== []) {
            return $this->partialResult($created, $originMode, $originServer, $warnings);
        }

        return [
            'ok' => true,
            'partial' => false,
            'created' => $created,
            'origin_mode' => $originMode,
            'message' => $this->successMessage($created, $originMode),
            'domain_setup' => $this->domainSetupPayload($created, $originServer, $originMode),
        ];
    }

    /**
     * @param  array<int, string>  $created
     * @param  array<int, string>  $warnings
     */
    private function partialResult(array $created, string $originMode, string $originServer, array $warnings): array
    {
        return [
            'ok' => $created !== [],
            'partial' => true,
            'created' => $created,
            'origin_mode' => $originMode,
            'warnings' => array_values(array_unique(array_filter($warnings))),
            'warning' => implode("\n", array_values(array_unique(array_filter($warnings)))),
            'message' => $this->successMessage($created, $originMode),
            'domain_setup' => $this->domainSetupPayload($created, $originServer, $originMode),
            'error' => $created === [] ? 'We could not queue provisioning for this domain.' : null,
        ];
    }

    /**
     * @param  array<int, string>  $domains
     * @return array<string, mixed>
     */
    private function domainSetupPayload(array $domains, string $originServer, string $originMode): array
    {
        return [
            'domains' => $domains,
            'cname_target' => (string) config('edgeshield.saas_cname_target', 'customers.verifysky.com'),
            'origin_server' => $originServer,
            'origin_mode' => $originMode,
            'provisioning_status' => TenantDomain::PROVISIONING_PENDING,
        ];
    }

    /**
     * @param  array<int, string>  $domains
     */
    private function successMessage(array $domains, string $originMode): string
    {
        $message = 'Provisioning started for '.implode(', ', $domains).'. Add the DNS record shown in the setup panel while VerifySky activates protection in the background.';

        if ($originMode === 'auto') {
            $message .= ' Backend origin detection will run in the provisioning queue.';
        }

        return $message;
    }

    private function upsertPendingTenantDomain(
        string $tenantId,
        string $hostname,
        string $originServer,
        string $securityMode,
        ?TenantDomain $existing
    ): TenantDomain {
        $domain = $existing instanceof TenantDomain ? $existing : new TenantDomain(['hostname' => $hostname]);
        $domain->forceFill([
            'tenant_id' => $tenantId,
            'hostname' => $hostname,
            'cname_target' => (string) config('edgeshield.saas_cname_target', 'customers.verifysky.com'),
            'origin_server' => $originServer,
            'cloudflare_custom_hostname_id' => null,
            'hostname_status' => 'pending',
            'ssl_status' => 'pending_validation',
            'security_mode' => $securityMode,
            'force_captcha' => false,
            'ownership_verification' => null,
            'provisioning_status' => TenantDomain::PROVISIONING_PENDING,
            'provisioning_error' => null,
            'provisioning_payload' => null,
            'provisioning_started_at' => null,
            'provisioning_finished_at' => null,
            'verified_at' => null,
        ])->save();

        return $domain->refresh();
    }

    private function dispatchProvisioningChain(TenantDomain $domain, string $requestedDomain, string $originServer): void
    {
        Bus::chain([
            new ValidateOriginServerJob((int) $domain->getKey(), $requestedDomain, $originServer === '' ? null : $originServer),
            new EnsureCloudflareWorkerRouteJob((int) $domain->getKey()),
            new ProvisionCloudflareSaasHostnameJob((int) $domain->getKey()),
            new SyncDomainConfigToD1Job((int) $domain->getKey()),
            new SyncSaasSecurityArtifactsJob((int) $domain->getKey()),
        ])
            ->onConnection(config('queue.default', 'database'))
            ->onQueue('default')
            ->dispatch();
    }

    private function isActiveDuplicate(TenantDomain $domain): bool
    {
        return (string) ($domain->provisioning_status ?? TenantDomain::PROVISIONING_ACTIVE) === TenantDomain::PROVISIONING_ACTIVE;
    }

    private function hasReusableSlot(?TenantDomain $domain): bool
    {
        if (! $domain instanceof TenantDomain) {
            return false;
        }

        return in_array((string) ($domain->provisioning_status ?? ''), [
            TenantDomain::PROVISIONING_PENDING,
            TenantDomain::PROVISIONING_PROVISIONING,
        ], true);
    }
}
