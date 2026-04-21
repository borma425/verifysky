<?php

namespace App\Actions\Domains;

use App\Services\EdgeShieldService;

class RefreshDomainGroupVerificationAction
{
    public function __construct(private readonly EdgeShieldService $edgeShield) {}

    public function execute(string $domain): array
    {
        $hostnames = $this->edgeShield->saasHostnamesForInput($domain);
        if (count($hostnames) === 0) {
            $hostnames = [$domain];
        }

        $refreshed = [];
        foreach ($hostnames as $hostname) {
            $sync = $this->edgeShield->refreshSaasCustomHostname($hostname);
            if (! $sync['ok']) {
                return ['ok' => false, 'error' => 'We could not refresh this domain yet. Please try again in a few minutes.'];
            }
            $this->edgeShield->purgeDomainConfigCache($hostname);
            $refreshed[] = $hostname;
        }

        return ['ok' => true, 'refreshed' => $refreshed];
    }
}
