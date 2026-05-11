<?php

namespace App\Services\EdgeShield\Concerns;

trait EdgeShieldInfrastructureFacade
{
    public function runInProject(string $command, int $timeout = 60): array
    {
        return $this->runner->runInProject($command, $timeout);
    }

    public function runWrangler(string $args, int $timeout = 60): array
    {
        return $this->runner->runWrangler($args, $timeout);
    }

    public function queryD1(string $sql, int $timeout = 90): array
    {
        return $this->d1->query($sql, $timeout);
    }

    public function parseWranglerJson(string $raw): array
    {
        return $this->d1->parseWranglerJson($raw);
    }

    public function allowIpViaWorkerAdmin(
        string $domain,
        string $ip,
        int $ttlHours = 24,
        string $reason = 'dashboard manual allow from logs'
    ): array {
        return $this->workerAdmin->allowIp($domain, $ip, $ttlHours, $reason);
    }

    public function getIpAdminStatusViaWorkerAdmin(string $domain, string $ip): array
    {
        return $this->workerAdmin->status($domain, $ip);
    }

    public function blockIpViaWorkerAdmin(
        string $domain,
        string $ip,
        int $ttlHours = 24,
        string $reason = 'dashboard manual block from logs'
    ): array {
        return $this->workerAdmin->blockIp($domain, $ip, $ttlHours, $reason);
    }

    public function revokeAllowIpViaWorkerAdmin(string $domain, string $ip): array
    {
        return $this->workerAdmin->revokeAllowIp($domain, $ip);
    }

    public function unbanIpViaWorkerAdmin(string $domain, string $ip): array
    {
        return $this->workerAdmin->unbanIp($domain, $ip);
    }

    public function cleanupIpViaWorkerAdmin(string $domain, string $ip): array
    {
        return $this->workerAdmin->cleanupIp($domain, $ip);
    }
}
