<?php

namespace App\Services;

use App\Services\EdgeShield\Concerns\EdgeShieldDomainAndSaasFacade;
use App\Services\EdgeShield\Concerns\EdgeShieldFirewallAndIpFacade;
use App\Services\EdgeShield\Concerns\EdgeShieldInfrastructureFacade;
use App\Services\EdgeShield\D1DatabaseClient;
use App\Services\EdgeShield\DomainConfigService;
use App\Services\EdgeShield\EdgeShieldConfig;
use App\Services\EdgeShield\FirewallRuleService;
use App\Services\EdgeShield\IpAccessRuleService;
use App\Services\EdgeShield\SaasHostnameService;
use App\Services\EdgeShield\SaasSecurityService;
use App\Services\EdgeShield\SensitivePathService;
use App\Services\EdgeShield\WorkerAdminClient;
use App\Services\EdgeShield\WorkerRouteService;
use App\Services\EdgeShield\WorkerSecretSyncService;
use App\Services\EdgeShield\WranglerProcessRunner;

class EdgeShieldService
{
    use EdgeShieldDomainAndSaasFacade;
    use EdgeShieldFirewallAndIpFacade;
    use EdgeShieldInfrastructureFacade;

    public function __construct(
        private readonly EdgeShieldConfig $config,
        private readonly WranglerProcessRunner $runner,
        private readonly D1DatabaseClient $d1,
        private readonly DomainConfigService $domains,
        private readonly SensitivePathService $sensitivePaths,
        private readonly FirewallRuleService $firewallRules,
        private readonly IpAccessRuleService $ipRules,
        private readonly SaasHostnameService $saasHostnames,
        private readonly SaasSecurityService $saasSecurity,
        private readonly WorkerSecretSyncService $secretSync,
        private readonly WorkerRouteService $workerRoutes,
        private readonly WorkerAdminClient $workerAdmin
    ) {}
}
