<?php

namespace App\Services\EdgeShield;

use App\Services\EdgeShield\Concerns\FirewallRuleMutationConcern;
use App\Services\EdgeShield\Concerns\FirewallRuleReadFarmConcern;

class FirewallRuleService
{
    use FirewallRuleMutationConcern;
    use FirewallRuleReadFarmConcern;

    public function __construct(
        private readonly D1DatabaseClient $d1,
        private readonly WorkerAdminClient $workerAdmin
    ) {}
}
