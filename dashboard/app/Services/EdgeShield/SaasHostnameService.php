<?php

namespace App\Services\EdgeShield;

use App\Services\EdgeShield\Concerns\SaasHostnameLifecycleConcern;
use App\Services\EdgeShield\Concerns\SaasHostnameOriginAliasConcern;
use App\Services\EdgeShield\Concerns\SaasHostnameOriginValidationConcern;
use App\Services\EdgeShield\Concerns\SaasHostnameProvisioningConcern;

class SaasHostnameService
{
    use SaasHostnameLifecycleConcern;
    use SaasHostnameOriginAliasConcern;
    use SaasHostnameOriginValidationConcern;
    use SaasHostnameProvisioningConcern;

    public function __construct(
        private readonly EdgeShieldConfig $config,
        private readonly CloudflareApiClient $cloudflare,
        private readonly D1DatabaseClient $d1,
        private readonly TurnstileService $turnstile,
        private readonly WorkerRouteService $workerRoutes,
        private readonly SaasSecurityService $saasSecurity
    ) {}
}
