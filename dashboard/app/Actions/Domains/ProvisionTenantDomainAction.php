<?php

namespace App\Actions\Domains;

use App\Models\TenantDomain;
use App\Services\EdgeShieldService;

class ProvisionTenantDomainAction
{
    public function __construct(private readonly EdgeShieldService $edgeShield) {}

    public function execute(array $validated, ?string $tenantId): array
    {
        $securityMode = $validated['security_mode'] ?? 'balanced';
        $originServer = trim((string) ($validated['origin_server'] ?? ''));
        $hostnames = $this->edgeShield->saasHostnamesForInput($validated['domain_name']);
        if (count($hostnames) === 0) {
            return ['ok' => false, 'error' => 'Domain name is empty.'];
        }

        $originMode = 'manual';
        if ($originServer === '') {
            $detectedOrigin = $this->edgeShield->detectOriginServerForInput($validated['domain_name']);
            if (! $detectedOrigin['ok']) {
                return [
                    'ok' => false,
                    'error' => $detectedOrigin['error'],
                    'origin_detection_failed' => true,
                ];
            }

            $originServer = (string) ($detectedOrigin['origin_server'] ?? '');
            $originMode = 'auto';
        }

        $created = [];
        foreach ($hostnames as $hostname) {
            $originValidation = $this->edgeShield->validateOriginServerForHostname($hostname, $originServer);
            if (! $originValidation['ok']) {
                return ['ok' => false, 'error' => $originValidation['error']];
            }

            $provisioned = $this->edgeShield->provisionSaasCustomHostname($hostname, $originServer);
            if (! $provisioned['ok']) {
                return ['ok' => false, 'error' => 'We could not start verification for this domain. Please check the hostname and try again.'];
            }

            $sql = sprintf(
                "INSERT INTO domain_configs (
                    domain_name, tenant_id, zone_id, turnstile_sitekey, turnstile_secret, status, force_captcha, security_mode,
                    custom_hostname_id, cname_target, origin_server, hostname_status, ssl_status, ownership_verification_json, updated_at
                 )
                 VALUES ('%s', '%s', '%s', '%s', '%s', 'active', 0, '%s', '%s', '%s', '%s', '%s', '%s', '%s', CURRENT_TIMESTAMP)
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
                str_replace("'", "''", (string) $provisioned['domain_name']),
                $tenantId ? str_replace("'", "''", (string) $tenantId) : '',
                str_replace("'", "''", (string) $provisioned['zone_id']),
                str_replace("'", "''", (string) $provisioned['turnstile_sitekey']),
                str_replace("'", "''", (string) $provisioned['turnstile_secret']),
                str_replace("'", "''", (string) $securityMode),
                str_replace("'", "''", (string) $provisioned['custom_hostname_id']),
                str_replace("'", "''", (string) $provisioned['cname_target']),
                str_replace("'", "''", (string) $originServer),
                str_replace("'", "''", (string) $provisioned['hostname_status']),
                str_replace("'", "''", (string) $provisioned['ssl_status']),
                str_replace("'", "''", (string) $provisioned['ownership_verification_json'])
            );

            $result = $this->edgeShield->queryD1($sql);
            if (! $result['ok']) {
                return ['ok' => false, 'error' => 'Domain verification started, but we could not save it in VerifySky. Please try again.'];
            }

            $route = $this->edgeShield->ensureWorkerRoute(
                (string) $provisioned['zone_id'],
                (string) $provisioned['domain_name']
            );
            if (! $route['ok']) {
                return ['ok' => false, 'error' => 'Domain verification started, but we could not route traffic through VerifySky Worker. '.$route['error']];
            }

            if ($tenantId) {
                TenantDomain::query()->updateOrCreate(
                    ['hostname' => (string) $provisioned['domain_name']],
                    [
                        'tenant_id' => $tenantId,
                        'cname_target' => (string) $provisioned['cname_target'],
                        'origin_server' => (string) $originServer,
                        'cloudflare_custom_hostname_id' => (string) $provisioned['custom_hostname_id'],
                        'hostname_status' => (string) $provisioned['hostname_status'],
                        'ssl_status' => (string) $provisioned['ssl_status'],
                        'security_mode' => $securityMode,
                        'ownership_verification' => (string) $provisioned['ownership_verification_json'],
                    ]
                );
            }

            $created[] = (string) $provisioned['domain_name'];
        }

        return ['ok' => true, 'created' => $created, 'origin_mode' => $originMode];
    }
}
