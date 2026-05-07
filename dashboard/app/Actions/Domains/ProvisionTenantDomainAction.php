<?php

namespace App\Actions\Domains;

use App\Jobs\Domains\EnsureCloudflareWorkerRouteJob;
use App\Jobs\Domains\ProvisionCloudflareSaasHostnameJob;
use App\Jobs\Domains\SyncDomainConfigToD1Job;
use App\Jobs\Domains\SyncSaasSecurityArtifactsJob;
use App\Jobs\Domains\ValidateOriginServerJob;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\Domains\DomainAssetPolicyService;
use App\Services\EdgeShieldService;
use App\Services\Plans\PlanLimitsService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProvisionTenantDomainAction
{
    public function __construct(
        private readonly EdgeShieldService $edgeShield,
        private readonly PlanLimitsService $planLimits,
        private readonly DomainAssetPolicyService $domainAssets
    ) {}

    public function execute(array $validated, ?string $tenantId, ?bool $isAdmin = null): array
    {
        $isAdmin = $isAdmin ?? (bool) session('is_admin');
        $tenantId = trim((string) $tenantId);
        if ($tenantId === '') {
            return ['ok' => false, 'error' => 'Please sign in again before adding a domain.'];
        }

        if (! Schema::hasTable('tenant_domains')) {
            return ['ok' => false, 'error' => 'Domain setup is not ready yet. Please try again later.'];
        }

        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant instanceof Tenant) {
            return ['ok' => false, 'error' => 'We could not find this account.'];
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

            $quarantine = $this->domainAssets->quarantineStatusForTenant($hostname, $tenant, $isAdmin);
            if ($quarantine['blocked']) {
                return $created === []
                    ? [
                        'ok' => false,
                        'error' => (string) $quarantine['message'],
                        'quarantine_blocked' => true,
                        'quarantined_until' => $quarantine['quarantined_until']?->toDateTimeString(),
                        'asset_key' => $quarantine['asset_key'],
                    ]
                    : $this->partialResult($created, $originMode, $originServer, array_merge($warnings, [
                        'One domain was skipped because it was recently removed from VerifySky.',
                    ]));
            }

            $existing = TenantDomain::query()->where('hostname', $hostname)->first();
            if ($existing instanceof TenantDomain && (string) $existing->tenant_id !== $tenantId) {
                return $created === []
                    ? ['ok' => false, 'error' => 'This domain is already used by another user.']
                    : $this->partialResult($created, $originMode, $originServer, array_merge($warnings, [
                        'One domain was skipped because another user already uses it.',
                    ]));
            }

            if ($existing instanceof TenantDomain && $this->isActiveDuplicate($existing)) {
                return $created === []
                    ? ['ok' => false, 'error' => 'This domain is already added to your account.']
                    : $this->partialResult($created, $originMode, $originServer, array_merge($warnings, [
                        'One domain was skipped because it is already added to this account.',
                    ]));
            }

            $domainsUsage = $this->planLimits->getDomainsUsage($tenant);
            if (! $this->hasReusableSlot($existing) && ! $domainsUsage['can_add']) {
                return $created === []
                    ? ['ok' => false, 'error' => (string) ($domainsUsage['message'] ?: 'You have reached the maximum number of domains for your current plan.')]
                    : $this->partialResult($created, $originMode, $originServer, array_merge($warnings, [
                        'One domain was skipped because this account has reached the domain limit.',
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

                $warnings[] = 'Domain '.$hostname.' was saved, but setup could not start yet.';
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
        $message = 'Setup started for '.implode(', ', $domains).'. Add the DNS record shown on this page while VerifySky turns on protection.';

        if ($originMode === 'auto') {
            $message .= ' VerifySky will try to find your server automatically.';
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
