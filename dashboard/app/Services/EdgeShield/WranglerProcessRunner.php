<?php

namespace App\Services\EdgeShield;

use App\Support\UserFacingErrorSanitizer;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class WranglerProcessRunner
{
    private ?bool $procOpenAvailable = null;

    public function __construct(private readonly EdgeShieldConfig $config) {}

    public function runInProject(string $command, int $timeout = 60): array
    {
        if (! $this->isProcOpenAvailable()) {
            return [
                'ok' => false,
                'exit_code' => 127,
                'output' => '',
                'error' => UserFacingErrorSanitizer::sanitize('PHP function proc_open is disabled on this server. Worker runtime commands cannot run from dashboard.'),
                'command' => $command,
            ];
        }

        $full = 'cd '.escapeshellarg($this->config->projectRoot()).' && '.$this->sanitizeCommandForNode($command);
        try {
            $process = Process::fromShellCommandline($full);
            $process->setTimeout($timeout);
            $process->run();
        } catch (\Throwable $e) {
            $rawError = $this->compactErrorMessage($e->getMessage());
            $this->logRawError($command, $rawError, 1);

            return [
                'ok' => false,
                'exit_code' => 1,
                'output' => '',
                'error' => UserFacingErrorSanitizer::sanitize($rawError),
                'command' => $command,
            ];
        }

        $rawError = trim($process->getErrorOutput());
        if (! $process->isSuccessful()) {
            $this->logRawError($command, $rawError, $process->getExitCode());
        }

        return [
            'ok' => $process->isSuccessful(),
            'exit_code' => $process->getExitCode(),
            'output' => trim($process->getOutput()),
            'error' => $this->sanitizeForUi($rawError, $process->isSuccessful()),
            'command' => $command,
        ];
    }

    public function runWrangler(string $args, int $timeout = 60): array
    {
        return $this->runInProject($this->config->wranglerBin().' '.$args, $timeout);
    }

    private function sanitizeCommandForNode(string $command): string
    {
        $runtimeHome = storage_path('wrangler-runtime');
        if (! is_dir($runtimeHome)) {
            @mkdir($runtimeHome, 0775, true);
        }
        $xdgConfig = $runtimeHome.'/.config';
        if (! is_dir($xdgConfig)) {
            @mkdir($xdgConfig, 0775, true);
        }

        $env = [
            'LD_LIBRARY_PATH' => '',
            'LD_PRELOAD' => '',
            'LIBRARY_PATH' => '',
            'HOME' => $runtimeHome,
            'XDG_CONFIG_HOME' => $xdgConfig,
            'WRANGLER_LOG_PATH' => $runtimeHome.'/logs',
        ];

        $nodeBinDir = $this->config->nodeBinDir();
        if ($nodeBinDir !== null) {
            $currentPath = (string) getenv('PATH');
            $parts = array_filter(explode(':', $currentPath), fn (string $path): bool => $path !== '');
            $safeParts = array_values(array_filter($parts, fn (string $path): bool => ! str_starts_with($path, '/opt/lampp')));
            $env['PATH'] = $nodeBinDir.':'.implode(':', $safeParts ?: ['/usr/local/bin', '/usr/bin', '/bin']);
        }

        $token = $this->config->cloudflareApiToken();
        if ($token !== null) {
            $env['CLOUDFLARE_API_TOKEN'] = $token;
        }

        $env = array_merge($env, $this->config->workerRuntimeEnvironment());
        $prefix = 'env -u LD_LIBRARY_PATH -u LD_PRELOAD -u LIBRARY_PATH';
        foreach ($env as $key => $value) {
            $prefix .= ' '.$key.'='.escapeshellarg($value);
        }

        return $prefix.' '.$command;
    }

    private function compactErrorMessage(string $error): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $error) ?? $error);
        if ($normalized === '') {
            return 'unknown error';
        }

        if (str_contains($normalized, 'GLIBCXX_') || str_contains($normalized, 'CXXABI_')) {
            return 'Node runtime mismatch detected (GLIBCXX/CXXABI). Check server Node/XAMPP runtime linkage.';
        }

        return mb_strimwidth($normalized, 0, 220, '...');
    }

    private function sanitizeForUi(string $rawError, bool $wasSuccessful): string
    {
        $normalized = trim($rawError);
        if ($normalized !== '') {
            return UserFacingErrorSanitizer::sanitize($normalized);
        }

        if ($wasSuccessful) {
            return '';
        }

        return UserFacingErrorSanitizer::defaultMessage();
    }

    private function logRawError(string $command, string $rawError, ?int $exitCode = null): void
    {
        Log::warning('Wrangler command failed.', [
            'command' => $command,
            'exit_code' => $exitCode,
            'raw_error' => $rawError,
        ]);
    }

    private function isProcOpenAvailable(): bool
    {
        if ($this->procOpenAvailable !== null) {
            return $this->procOpenAvailable;
        }

        if (! function_exists('proc_open')) {
            return $this->procOpenAvailable = false;
        }

        $disabled = (string) ini_get('disable_functions');
        $disabledFunctions = array_map(
            static fn (string $item): string => trim(strtolower($item)),
            explode(',', strtolower($disabled))
        );

        return $this->procOpenAvailable = ! in_array('proc_open', $disabledFunctions, true);
    }
}
