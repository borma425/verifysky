<?php

namespace Tests\Unit;

use App\Services\EdgeShield\D1DatabaseClient;
use App\Services\EdgeShield\EdgeShieldConfig;
use App\Services\EdgeShield\WranglerProcessRunner;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class D1DatabaseClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_local_mode_uses_local_wrangler_without_remote_api(): void
    {
        $config = new class extends EdgeShieldConfig
        {
            public bool $remoteCredentialsWereRead = false;

            public function useLocalD1(): bool
            {
                return true;
            }

            public function wranglerBin(): string
            {
                return 'npx wrangler';
            }

            public function d1DatabaseName(): string
            {
                return 'VERIFY_SKY_TEST_DB';
            }

            public function cloudflareAccountId(): ?string
            {
                $this->remoteCredentialsWereRead = true;

                return 'account-id';
            }

            public function cloudflareApiToken(): ?string
            {
                $this->remoteCredentialsWereRead = true;

                return 'token';
            }
        };

        $runner = new class($config) extends WranglerProcessRunner
        {
            public string $command = '';

            public int $timeout = 0;

            public function runInProject(string $command, int $timeout = 60): array
            {
                $this->command = $command;
                $this->timeout = $timeout;

                return ['ok' => true, 'output' => '[]', 'error' => null];
            }
        };

        $result = (new D1DatabaseClient($config, $runner))->query('SELECT 1', 1);

        $this->assertTrue($result['ok']);
        $this->assertSame(10, $runner->timeout);
        $this->assertStringContainsString('--local', $runner->command);
        $this->assertStringNotContainsString('--remote', $runner->command);
        $this->assertStringContainsString('VERIFY_SKY_TEST_DB', $runner->command);
        $this->assertFalse($config->remoteCredentialsWereRead);
    }

    public function test_local_select_queries_are_cached_for_the_ttl_window(): void
    {
        $config = new class extends EdgeShieldConfig
        {
            public function useLocalD1(): bool
            {
                return true;
            }

            public function wranglerBin(): string
            {
                return 'npx wrangler';
            }

            public function d1DatabaseName(): string
            {
                return 'VERIFY_SKY_TEST_DB';
            }

            public function d1ReadCacheTtl(): int
            {
                return 60;
            }
        };

        $runner = new class($config) extends WranglerProcessRunner
        {
            public int $calls = 0;

            public function runInProject(string $command, int $timeout = 60): array
            {
                $this->calls++;

                return ['ok' => true, 'output' => 'cached-output-'.$this->calls, 'error' => null];
            }
        };

        $client = new D1DatabaseClient($config, $runner);

        $first = $client->query("SELECT *  FROM domain_configs WHERE domain_name = 'example.com'");
        $second = $client->query("SELECT * FROM   domain_configs WHERE domain_name = 'example.com'");

        $this->assertTrue($first['ok']);
        $this->assertSame('cached-output-1', $first['output']);
        $this->assertSame($first, $second);
        $this->assertSame(1, $runner->calls);
    }

    public function test_successful_write_invalidates_the_local_read_cache_namespace(): void
    {
        $config = new class extends EdgeShieldConfig
        {
            public function useLocalD1(): bool
            {
                return true;
            }

            public function wranglerBin(): string
            {
                return 'npx wrangler';
            }

            public function d1DatabaseName(): string
            {
                return 'VERIFY_SKY_TEST_DB';
            }

            public function d1ReadCacheTtl(): int
            {
                return 60;
            }
        };

        $runner = new class($config) extends WranglerProcessRunner
        {
            public int $calls = 0;

            public function runInProject(string $command, int $timeout = 60): array
            {
                $this->calls++;

                return match (true) {
                    str_contains($command, 'SELECT') && $this->calls === 1 => ['ok' => true, 'output' => 'select-before-write', 'error' => null],
                    str_contains($command, 'UPDATE') => ['ok' => true, 'output' => 'write-ok', 'error' => null],
                    str_contains($command, 'SELECT') && $this->calls === 3 => ['ok' => true, 'output' => 'select-after-write', 'error' => null],
                    default => ['ok' => false, 'output' => '', 'error' => 'Unexpected command: '.$command],
                };
            }
        };

        $client = new D1DatabaseClient($config, $runner);

        $firstRead = $client->query("SELECT * FROM domain_configs WHERE domain_name = 'example.com'");
        $write = $client->query("UPDATE domain_configs SET status = 'paused' WHERE domain_name = 'example.com'");
        $secondRead = $client->query("SELECT * FROM domain_configs WHERE domain_name = 'example.com'");

        $this->assertTrue($firstRead['ok']);
        $this->assertTrue($write['ok']);
        $this->assertTrue($secondRead['ok']);
        $this->assertSame('select-before-write', $firstRead['output']);
        $this->assertSame('select-after-write', $secondRead['output']);
        $this->assertSame(3, $runner->calls);
    }

    public function test_non_local_mode_requires_explicit_cloudflare_api_configuration(): void
    {
        $config = new class extends EdgeShieldConfig
        {
            public function useLocalD1(): bool
            {
                return false;
            }

            public function configuredD1DatabaseId(): ?string
            {
                return null;
            }

            public function cloudflareAccountId(): ?string
            {
                return null;
            }

            public function cloudflareApiToken(): ?string
            {
                return null;
            }
        };

        $runner = new class($config) extends WranglerProcessRunner
        {
            public int $calls = 0;

            public function runInProject(string $command, int $timeout = 60): array
            {
                $this->calls++;

                return ['ok' => true, 'output' => '[]', 'error' => null];
            }
        };

        $result = (new D1DatabaseClient($config, $runner))->query('SELECT 1');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Cloudflare D1 API configuration is incomplete.', (string) $result['error']);
        $this->assertStringContainsString('CLOUDFLARE_ACCOUNT_ID', (string) $result['error']);
        $this->assertStringContainsString('CLOUDFLARE_API_TOKEN', (string) $result['error']);
        $this->assertStringContainsString('D1_DATABASE_ID', (string) $result['error']);
        $this->assertStringContainsString('Wrangler remote fallback is disabled.', (string) $result['error']);
        $this->assertSame(0, $runner->calls);
    }
}
