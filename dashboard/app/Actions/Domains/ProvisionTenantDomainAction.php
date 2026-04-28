<?php

namespace App\Actions\Domains;

use App\Models\TenantDomain;
use App\Services\Domains\DnsVerificationService;
use App\Services\EdgeShieldService;

class ProvisionTenantDomainAction
{
    public function __construct(
        private readonly EdgeShieldService $edgeShield,
        private readonly DnsVerificationService $dnsVerification
    ) {}

    public function execute(array $validated, ?string $tenantId): array
    {
        $securityMode = $validated['security_mode'] ?? 'balanced';
        $originServer = trim((string) ($validated['origin_server'] ?? ''));
        $requestedDomain = $this->dnsVerification->normalizeDomain((string) $validated['domain_name']);
        $apexMode = $this->normalizeApexMode((string) ($validated['apex_mode'] ?? TenantDomain::APEX_MODE_WWW_REDIRECT), $requestedDomain);
        $dnsProvider = $this->normalizeDnsProvider((string) ($validated['dns_provider'] ?? 'other'));
        $canonicalHostname = $this->dnsVerification->canonicalHostname($requestedDomain, $apexMode);
        $hostnames = $this->edgeShield->saasHostnamesForInput($requestedDomain, $apexMode);
        if (count($hostnames) === 0) {
            return ['ok' => false, 'error' => 'Domain name is empty.'];
        }

        $originMode = 'manual';
        if ($originServer === '') {
            $detectedOrigin = $this->edgeShield->detectOriginServerForInput($requestedDomain);
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
                        'requested_domain' => $requestedDomain,
                        'canonical_hostname' => $canonicalHostname,
                        'apex_mode' => $apexMode,
                        'dns_provider' => $dnsProvider,
                        'apex_redirect_status' => $apexMode === TenantDomain::APEX_MODE_WWW_REDIRECT
                            ? TenantDomain::REDIRECT_STATUS_UNCHECKED
                            : null,
                        'apex_redirect_checked_at' => null,
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

        return [
            'ok' => true,
            'created' => $created,
            'origin_mode' => $originMode,
            'message' => $this->successMessage($created, $originMode, $apexMode),
            'domain_setup' => $this->domainSetupPayload($requestedDomain, $canonicalHostname, $created, $apexMode, $dnsProvider, $originServer, $originMode),
        ];
    }

    private function normalizeApexMode(string $mode, string $domain): string
    {
        if (! $this->dnsVerification->looksLikeApexDomain($domain) || str_starts_with($domain, 'www.')) {
            return TenantDomain::APEX_MODE_SUBDOMAIN_ONLY;
        }

        return in_array($mode, [
            TenantDomain::APEX_MODE_WWW_REDIRECT,
            TenantDomain::APEX_MODE_DIRECT_APEX,
            TenantDomain::APEX_MODE_SUBDOMAIN_ONLY,
        ], true) ? $mode : TenantDomain::APEX_MODE_WWW_REDIRECT;
    }

    private function normalizeDnsProvider(string $provider): string
    {
        return in_array($provider, ['cloudflare', 'namecheap', 'godaddy', 'spaceship', 'other'], true) ? $provider : 'other';
    }

    /**
     * @param  array<int, string>  $created
     * @return array<string, mixed>
     */
    private function domainSetupPayload(
        string $requestedDomain,
        string $canonicalHostname,
        array $created,
        string $apexMode,
        string $dnsProvider,
        string $originServer,
        string $originMode
    ): array {
        $target = $this->edgeShield->saasCnameTarget();

        return [
            'domains' => $created,
            'protected_hostnames' => $created,
            'requested_domain' => $requestedDomain,
            'canonical_hostname' => $canonicalHostname,
            'setup_profile' => $apexMode,
            'dns_provider' => $dnsProvider,
            'cname_target' => $target,
            'origin_server' => $originServer,
            'origin_mode' => $originMode,
            'dns_records' => $this->dnsRecords($created, $requestedDomain, $apexMode, $target),
            'redirect_instruction' => $this->redirectInstruction($requestedDomain, $canonicalHostname, $apexMode, $dnsProvider),
            'provider_notes' => $this->providerNotes($dnsProvider, $apexMode),
        ];
    }

    /**
     * @param  array<int, string>  $hostnames
     * @return array<int, array<string, string>>
     */
    private function dnsRecords(array $hostnames, string $requestedDomain, string $apexMode, string $target): array
    {
        return array_map(function (string $hostname) use ($requestedDomain, $apexMode, $target): array {
            $isRoot = $hostname === $requestedDomain && $this->dnsVerification->looksLikeApexDomain($hostname);

            return [
                'type' => $isRoot && $apexMode === TenantDomain::APEX_MODE_DIRECT_APEX ? 'ALIAS / ANAME / Flattened CNAME' : 'CNAME',
                'name' => $isRoot ? '@' : (str_starts_with($hostname, 'www.') && substr($hostname, 4) === $requestedDomain ? 'www' : explode('.', $hostname)[0]),
                'content' => $target,
                'hostname' => $hostname,
            ];
        }, $hostnames);
    }

    /**
     * @return array<string, string>|null
     */
    private function redirectInstruction(string $requestedDomain, string $canonicalHostname, string $apexMode, string $dnsProvider): ?array
    {
        if ($apexMode !== TenantDomain::APEX_MODE_WWW_REDIRECT || $requestedDomain === $canonicalHostname) {
            return null;
        }

        return [
            'type' => '301 / 308 Permanent Redirect',
            'from' => $requestedDomain,
            'to' => 'https://'.$canonicalHostname,
            'status' => 'unchecked',
            'provider' => $dnsProvider,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function providerNotes(string $dnsProvider, string $apexMode): array
    {
        if ($apexMode === TenantDomain::APEX_MODE_DIRECT_APEX) {
            return match ($dnsProvider) {
                'cloudflare' => ['Use a CNAME record at @. Cloudflare will flatten it automatically at the root domain.'],
                'namecheap' => ['Use an ALIAS record at @ when the root domain cannot use a regular CNAME.'],
                'godaddy' => ['GoDaddy commonly does not support ALIAS at the root. Use the recommended www + root redirect mode unless you move DNS to a provider with ALIAS support.'],
                default => ['Use ALIAS, ANAME, or flattened CNAME for the root domain if your DNS provider supports it.'],
            };
        }

        if ($apexMode === TenantDomain::APEX_MODE_WWW_REDIRECT) {
            return match ($dnsProvider) {
                'cloudflare' => ['Create the www CNAME, then add a Redirect Rule from the root domain to https://www.'],
                'godaddy' => ['Create the www CNAME, then use Domain Forwarding from the root domain to the www hostname. Use a permanent redirect when available.'],
                default => ['Create the www CNAME, then configure a permanent 301 or 308 redirect from the root domain to the www hostname.'],
            };
        }

        return ['This setup protects only the exact hostname entered.'];
    }

    /**
     * @param  array<int, string>  $domains
     */
    private function successMessage(array $domains, string $originMode, string $apexMode): string
    {
        $message = 'Route creation started for '.implode(', ', $domains).'. Add the DNS record shown in the setup panel to continue verification.';
        if ($apexMode === TenantDomain::APEX_MODE_WWW_REDIRECT) {
            $message .= ' Configure a permanent root redirect to complete root-domain handling.';
        }
        if ($originMode === 'auto') {
            $message .= ' Backend origin was detected automatically.';
        }

        return $message;
    }
}
