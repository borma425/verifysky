<?php

namespace App\Jobs;

use App\Services\Cloudflare\KVPurgeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PurgeRuntimeBundleCache implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 10;

    /**
     * @var array<int, int>
     */
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly string $domain)
    {
        $this->onQueue('purges');
    }

    public function handle(KVPurgeService $purge): void
    {
        $result = $purge->purgeDomain($this->domain);

        if (! ($result['ok'] ?? false)) {
            throw new \RuntimeException('Runtime bundle cache purge failed for domain '.$this->domain);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Runtime bundle cache purge failed.', [
            'domain' => $this->domain,
            'error' => $exception?->getMessage(),
        ]);
    }
}
