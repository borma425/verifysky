<?php

namespace App\Actions\Logs;

use App\Repositories\SecurityLogRepository;

class ClearSecurityLogsAction
{
    public function __construct(private readonly SecurityLogRepository $logs) {}

    public function execute(string $period, ?string $tenantId = null, bool $isAdmin = true): array
    {
        $result = $this->logs->clearLogs($period, $tenantId, $isAdmin);
        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'Failed to clear logs: '.((string) ($result['error'] ?? 'unknown error'))];
        }

        $this->logs->bumpCacheVersion();

        return ['ok' => true, 'message' => 'Logs cleared successfully.'];
    }
}
