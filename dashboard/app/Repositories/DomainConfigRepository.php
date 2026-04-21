<?php

namespace App\Repositories;

use App\Services\EdgeShieldService;

class DomainConfigRepository
{
    public function __construct(private readonly EdgeShieldService $edgeShield) {}

    public function listForTenant(?string $tenantId, bool $isAdmin): array
    {
        return $this->edgeShield->listDomains($tenantId, $isAdmin);
    }
}
