<?php

namespace App\Console\Commands;

use App\Services\Billing\CloudflareCostAttributionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SyncCloudflareCosts extends Command
{
    protected $signature = 'billing:sync-cloudflare-costs
        {--from= : UTC start datetime, defaults to 24 hours ago}
        {--to= : UTC end datetime, defaults to now}
        {--environment=production : Cloudflare telemetry environment}
        {--dry-run : Query WAE without writing local usage/cost rows}';

    protected $description = 'Sync tenant/domain Cloudflare usage from Workers Analytics Engine and estimate daily costs.';

    public function handle(CloudflareCostAttributionService $costs): int
    {
        $to = $this->parseTime((string) $this->option('to'), CarbonImmutable::now('UTC'));
        $from = $this->parseTime((string) $this->option('from'), $to->subDay());

        if ($from->gte($to)) {
            $this->error('The --from value must be earlier than --to.');

            return self::INVALID;
        }

        $environment = $this->environmentName();
        $result = $costs->syncUsage($from, $to, $environment, (bool) $this->option('dry-run'));

        if (! $result['ok']) {
            $this->error($result['error'] ?? 'Cloudflare cost sync failed.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Cloudflare cost sync completed. Environment: %s. Rows: %d.%s',
            $environment,
            $result['rows'],
            $result['dry_run'] ? ' Dry run only.' : ''
        ));

        return self::SUCCESS;
    }

    private function parseTime(string $value, CarbonImmutable $default): CarbonImmutable
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $default->utc();
        }

        return CarbonImmutable::parse($trimmed, 'UTC')->utc();
    }

    private function environmentName(): string
    {
        $environment = strtolower(trim((string) $this->option('environment')));

        return $environment === 'staging' ? 'staging' : 'production';
    }
}
