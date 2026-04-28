<?php

namespace App\Jobs\Domains;

use App\Services\Domains\DomainProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncSaasSecurityArtifactsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 90;

    public function __construct(public readonly int $tenantDomainId) {}

    public function handle(DomainProvisioningService $provisioning): void
    {
        $provisioning->syncSaasSecurityArtifacts($this->tenantDomainId);
    }

    public function failed(Throwable $exception): void
    {
        app(DomainProvisioningService::class)->markFailed($this->tenantDomainId, $exception);
    }
}
