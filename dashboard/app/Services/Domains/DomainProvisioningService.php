<?php

namespace App\Services\Domains;

use App\Models\TenantDomain;
use App\Services\EdgeShieldService;
use App\Support\UserFacingErrorSanitizer;
use RuntimeException;
use Throwable;

class DomainProvisioningService
{
    public function __construct(private readonly EdgeShieldService $edgeShield) {}

    public function markProvisioning(int $tenantDomainId): TenantDomain
    {
        $domain = $this->domain($tenantDomainId);
        $domain->forceFill([
            'provisioning_status' => TenantDomain::PROVISIONING_PROVISIONING,
            'provisioning_error' => null,
            'provisioning_started_at' => $domain->provisioning_started_at ?? now(),
            'provisioning_finished_at' => null,
        ])->save();

        return $domain->refresh();
    }

    public function validateOriginServer(int $tenantDomainId, string $requestedDomain, ?string $originServer): void
    {
        $domain = $this->markProvisioning($tenantDomainId);
        $origin = trim((string) $originServer);

        if ($origin === '') {
            $detected = $this->edgeShield->detectOriginServerForInput($requestedDomain);
            if (! ($detected['ok'] ?? false)) {
                throw new RuntimeException((string) ($detected['error'] ?? 'We could not find the server for this domain.'));
            }

            $origin = trim((string) ($detected['origin_server'] ?? ''));
        }

        if ($origin === '') {
            throw new RuntimeException('Server is required before domain setup can continue.');
        }

        $validation = $this->edgeShield->validateOriginServerForHostname((string) $domain->hostname, $origin);
        if (! ($validation['ok'] ?? false)) {
            throw new RuntimeException((string) ($validation['error'] ?? 'We could not check the server for this domain.'));
        }

        $domain->forceFill(['origin_server' => $origin])->save();
    }

    public function ensureWorkerRoute(int $tenantDomainId): void
    {
        $domain = $this->domain($tenantDomainId);
        $zoneId = trim((string) $this->edgeShield->saasZoneId());
        if ($zoneId === '') {
            throw new RuntimeException('Edge Zone ID is missing. Add it in Settings.');
        }

        $result = $this->edgeShield->ensureWorkerRouteOnly($zoneId, (string) $domain->hostname);
        if (! ($result['ok'] ?? false)) {
            throw new RuntimeException((string) ($result['error'] ?? 'We could not sync the Worker route for this domain.'));
        }
    }

    public function provisionCloudflareHostname(int $tenantDomainId): void
    {
        $domain = $this->domain($tenantDomainId);
        $result = $this->edgeShield->provisionSaasCustomHostname(
            (string) $domain->hostname,
            (string) $domain->origin_server
        );

        if (! ($result['ok'] ?? false)) {
            throw new RuntimeException((string) ($result['error'] ?? 'We could not start verification for this domain.'));
        }

        $hostnameStatus = strtolower(trim((string) ($result['hostname_status'] ?? 'pending')));
        $sslStatus = strtolower(trim((string) ($result['ssl_status'] ?? 'pending_validation')));

        $domain->forceFill([
            'cname_target' => (string) ($result['cname_target'] ?? config('edgeshield.saas_cname_target', 'customers.verifysky.com')),
            'origin_server' => (string) ($result['effective_origin_server'] ?? $domain->origin_server),
            'cloudflare_custom_hostname_id' => (string) ($result['custom_hostname_id'] ?? ''),
            'hostname_status' => $hostnameStatus,
            'ssl_status' => $sslStatus,
            'ownership_verification' => $this->decodeJson($result['ownership_verification_json'] ?? null),
            'provisioning_payload' => $result,
            'provisioning_status' => $this->isVerified($hostnameStatus, $sslStatus)
                ? TenantDomain::PROVISIONING_ACTIVE
                : TenantDomain::PROVISIONING_PROVISIONING,
            'verified_at' => $this->isVerified($hostnameStatus, $sslStatus) ? now() : null,
            'provisioning_finished_at' => $this->isVerified($hostnameStatus, $sslStatus) ? now() : null,
        ])->save();
    }

    public function syncDomainConfigToD1(int $tenantDomainId): void
    {
        $domain = $this->domain($tenantDomainId);
        $payload = is_array($domain->provisioning_payload) ? $domain->provisioning_payload : [];
        if ($payload === []) {
            throw new RuntimeException('Domain setup data is missing.');
        }

        $sql = sprintf(
            "INSERT INTO domain_configs (
                domain_name, tenant_id, zone_id, turnstile_sitekey, turnstile_secret, status, force_captcha, security_mode,
                custom_hostname_id, cname_target, origin_server, hostname_status, ssl_status, ownership_verification_json, updated_at
             )
             VALUES ('%s', '%s', '%s', '%s', '%s', 'active', %d, '%s', '%s', '%s', '%s', '%s', '%s', '%s', CURRENT_TIMESTAMP)
             ON CONFLICT(domain_name) DO UPDATE SET
               tenant_id = excluded.tenant_id,
               zone_id = excluded.zone_id,
               turnstile_sitekey = excluded.turnstile_sitekey,
               turnstile_secret = excluded.turnstile_secret,
               security_mode = excluded.security_mode,
               custom_hostname_id = excluded.custom_hostname_id,
               cname_target = excluded.cname_target,
               origin_server = excluded.origin_server,
               hostname_status = excluded.hostname_status,
               ssl_status = excluded.ssl_status,
               ownership_verification_json = excluded.ownership_verification_json,
               updated_at = CURRENT_TIMESTAMP,
               status = 'active'",
            $this->escape((string) $domain->hostname),
            $this->escape((string) $domain->tenant_id),
            $this->escape((string) ($payload['zone_id'] ?? '')),
            $this->escape((string) ($payload['turnstile_sitekey'] ?? '')),
            $this->escape((string) ($payload['turnstile_secret'] ?? '')),
            $domain->force_captcha ? 1 : 0,
            $this->escape((string) ($domain->security_mode ?? 'balanced')),
            $this->escape((string) ($domain->cloudflare_custom_hostname_id ?? '')),
            $this->escape((string) ($domain->cname_target ?? config('edgeshield.saas_cname_target', 'customers.verifysky.com'))),
            $this->escape((string) ($domain->origin_server ?? '')),
            $this->escape((string) ($domain->hostname_status ?? 'pending')),
            $this->escape((string) ($domain->ssl_status ?? 'pending_validation')),
            $this->escape((string) json_encode($domain->ownership_verification))
        );

        $result = $this->edgeShield->queryD1($sql);
        if (! ($result['ok'] ?? false)) {
            throw new RuntimeException((string) ($result['error'] ?? 'VerifySky could not save the D1 domain configuration.'));
        }
    }

    public function syncSaasSecurityArtifacts(int $tenantDomainId): void
    {
        $domain = $this->domain($tenantDomainId);
        $payload = is_array($domain->provisioning_payload) ? $domain->provisioning_payload : [];
        $zoneId = trim((string) ($payload['zone_id'] ?? $this->edgeShield->saasZoneId()));

        if ($zoneId === '') {
            throw new RuntimeException('Edge Zone ID is missing. Add it in Settings.');
        }

        $checks = [
            $this->edgeShield->ensureSaasBotManagementSettings(),
            $this->edgeShield->ensureSaasFallbackBypassRules(),
            $this->edgeShield->ensureCacheRuleForEdgeShield($zoneId, (string) $domain->hostname),
        ];

        foreach ($checks as $check) {
            if (! ($check['ok'] ?? false)) {
                throw new RuntimeException((string) ($check['error'] ?? 'VerifySky could not sync one or more edge security artifacts.'));
            }
        }
    }

    public function markFailed(int $tenantDomainId, Throwable|string $error): void
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;

        TenantDomain::query()
            ->whereKey($tenantDomainId)
            ->update([
                'provisioning_status' => TenantDomain::PROVISIONING_FAILED,
                'provisioning_error' => UserFacingErrorSanitizer::sanitize(
                    $message,
                    'Domain setup failed. Please try again or contact support.'
                ),
                'provisioning_finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function markActiveIfVerified(TenantDomain $domain): void
    {
        $hostnameStatus = strtolower(trim((string) $domain->hostname_status));
        $sslStatus = strtolower(trim((string) $domain->ssl_status));
        if (! $this->isVerified($hostnameStatus, $sslStatus)) {
            return;
        }

        $domain->forceFill([
            'provisioning_status' => TenantDomain::PROVISIONING_ACTIVE,
            'provisioning_error' => null,
            'verified_at' => $domain->verified_at ?? now(),
            'provisioning_finished_at' => $domain->provisioning_finished_at ?? now(),
        ])->save();
    }

    private function domain(int $tenantDomainId): TenantDomain
    {
        return TenantDomain::query()->findOrFail($tenantDomainId);
    }

    private function isVerified(string $hostnameStatus, string $sslStatus): bool
    {
        return $hostnameStatus === 'active' && $sslStatus === 'active';
    }

    private function escape(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    private function decodeJson(mixed $json): mixed
    {
        if (is_array($json)) {
            return $json;
        }

        $decoded = json_decode((string) $json, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
