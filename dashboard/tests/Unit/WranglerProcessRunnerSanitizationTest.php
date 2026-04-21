<?php

namespace Tests\Unit;

use App\Services\EdgeShield\EdgeShieldConfig;
use App\Services\EdgeShield\WranglerProcessRunner;
use App\Support\UserFacingErrorSanitizer;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WranglerProcessRunnerSanitizationTest extends TestCase
{
    public function test_failed_wrangler_command_returns_sanitized_error_and_logs_raw_stderr(): void
    {
        if (! $this->procOpenAvailable()) {
            $this->markTestSkipped('proc_open is not available in this runtime.');
        }

        $config = new class extends EdgeShieldConfig
        {
            public function projectRoot(): string
            {
                return base_path();
            }
        };

        $runner = new WranglerProcessRunner($config);
        $rawError = "\033[31m✘ \033[41;31m[\033[41;97mERROR\033[41;31m]\033[0m no such column: tenant_id at offset 76: SQLITE_ERROR 🪵 Logs were written to \"/opt/lampp/htdocs/verifysky/dashboard/storage/wrangler-runtime/logs/wrangler-2026-04-21_15-16-50_785.log\"";
        $command = 'bash -lc '.escapeshellarg('printf %s '.escapeshellarg($rawError).' 1>&2; exit 1');

        Log::spy();
        $result = $runner->runInProject($command, 10);

        $this->assertFalse($result['ok']);
        $this->assertSame(UserFacingErrorSanitizer::defaultMessage(), $result['error']);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Wrangler command failed.'
                    && str_contains((string) ($context['raw_error'] ?? ''), 'SQLITE_ERROR')
                    && ! empty($context['command']);
            });
    }

    private function procOpenAvailable(): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }

        $disabled = strtolower((string) ini_get('disable_functions'));
        $disabledFunctions = array_map('trim', explode(',', $disabled));

        return ! in_array('proc_open', $disabledFunctions, true);
    }
}
