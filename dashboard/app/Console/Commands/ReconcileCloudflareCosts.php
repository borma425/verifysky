<?php

namespace App\Console\Commands;

use App\Services\Billing\CloudflareCostAttributionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ReconcileCloudflareCosts extends Command
{
    protected $signature = 'billing:reconcile-cloudflare-costs
        {--period= : Billing period in YYYY-MM format}
        {--actual-cost= : Actual Cloudflare cost in USD for the period. If omitted, the Cloudflare PayGo API is used.}
        {--environment=production : Cloudflare telemetry environment}
        {--resource=cloudflare_total : Snapshot resource label}';

    protected $description = 'Allocate an actual Cloudflare account-level cost across tenant/domain estimates pro-rata.';

    public function handle(CloudflareCostAttributionService $costs): int
    {
        $period = trim((string) $this->option('period'));
        $actualCost = $this->option('actual-cost');

        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            $this->error('The --period option must use YYYY-MM.');

            return self::INVALID;
        }

        $start = CarbonImmutable::parse($period.'-01 00:00:00', 'UTC')->startOfMonth();
        $end = $start->addMonth();
        $environment = strtolower(trim((string) $this->option('environment'))) === 'staging' ? 'staging' : 'production';
        $resource = trim((string) $this->option('resource')) ?: 'cloudflare_total';
        $source = 'manual_reconciliation';

        if ($actualCost === null || trim((string) $actualCost) === '') {
            $paygo = $costs->fetchPaygoCostForPeriod($start, $end);
            if (! $paygo['ok']) {
                $this->error($paygo['error'] ?? 'Cloudflare PayGo billing lookup failed.');

                return self::FAILURE;
            }

            $actualCost = $paygo['total'];
            $source = 'cloudflare_paygo';
        }

        if (! is_numeric($actualCost) || (float) $actualCost < 0) {
            $this->error('The --actual-cost option must be a non-negative USD amount.');

            return self::INVALID;
        }

        $result = $costs->reconcilePeriod($start, $end, (float) $actualCost, $environment, $resource, $source);
        if (! $result['ok']) {
            $this->error($result['error'] ?? 'Cloudflare cost reconciliation failed.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Cloudflare cost reconciliation completed. Estimated: $%.6f. Actual: $%.6f. Rows updated: %d.',
            $result['total_estimated'],
            $result['total_actual'],
            $result['updated']
        ));

        return self::SUCCESS;
    }
}
