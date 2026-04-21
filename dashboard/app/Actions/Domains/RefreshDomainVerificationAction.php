<?php

namespace App\Actions\Domains;

use App\Services\EdgeShieldService;

class RefreshDomainVerificationAction
{
    public function __construct(private readonly EdgeShieldService $edgeShield) {}

    public function execute(string $domain): array
    {
        $result = $this->edgeShield->refreshSaasCustomHostname($domain);
        if ($result['ok']) {
            $this->edgeShield->purgeDomainConfigCache($domain);
        }

        return $result;
    }
}
