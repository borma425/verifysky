<?php

namespace Tests\Unit;

use App\Models\DashboardSetting;
use App\Services\EdgeShield\EdgeShieldConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class EdgeShieldConfigTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            if (is_file($directory.'/wrangler.toml')) {
                @unlink($directory.'/wrangler.toml');
            }

            if (is_dir($directory.'/src')) {
                @rmdir($directory.'/src');
            }

            @rmdir($directory);
        }

        parent::tearDown();
    }

    public function test_worker_script_name_defaults_to_production_script(): void
    {
        Config::set('edgeshield.worker_name', null);
        Config::set('edgeshield.target_env', 'production');

        $config = new EdgeShieldConfig;

        $this->assertSame('verifysky-edge', $config->workerScriptName());
    }

    public function test_staging_target_selects_staging_worker_and_wrangler_environment(): void
    {
        Config::set('edgeshield.worker_name', '');
        Config::set('edgeshield.target_env', 'staging');

        $config = new EdgeShieldConfig;

        $this->assertSame('staging', $config->targetEnvironment());
        $this->assertSame('Staging Remote', $config->targetEnvironmentLabel());
        $this->assertSame('verifysky-edge-staging', $config->workerScriptName());
        $this->assertSame('staging', $config->wranglerEnvironmentName());
        $this->assertTrue($config->allowsCloudflareMutations());
    }

    public function test_production_readonly_target_blocks_mutations_but_allows_selects(): void
    {
        Config::set('edgeshield.target_env', 'production_readonly');

        $config = new EdgeShieldConfig;

        $this->assertSame('Production Read-Only', $config->targetEnvironmentLabel());
        $this->assertFalse($config->allowsCloudflareMutations());
        $this->assertTrue($config->canRunD1Query('SELECT * FROM security_logs'));
        $this->assertTrue($config->canRunD1Query('WITH recent AS (SELECT 1) SELECT * FROM recent'));
        $this->assertFalse($config->canRunD1Query('DELETE FROM security_logs'));
    }

    public function test_cloudflare_credentials_do_not_fall_back_to_dashboard_settings(): void
    {
        Config::set('edgeshield.cloudflare_api_token', '');
        Config::set('edgeshield.cloudflare_account_id', '');

        DashboardSetting::query()->create(['key' => 'cf_api_token', 'value' => 'token-from-settings']);
        DashboardSetting::query()->create(['key' => 'cf_account_id', 'value' => 'account-from-settings']);

        $config = new EdgeShieldConfig;

        $this->assertNull($config->cloudflareApiToken());
        $this->assertNull($config->cloudflareAccountId());
    }

    public function test_saas_zone_id_does_not_fall_back_to_dashboard_settings(): void
    {
        Config::set('edgeshield.saas_zone_id', '');

        DashboardSetting::query()->create(['key' => 'cf_zone_id', 'value' => 'zone-from-settings']);

        $config = new EdgeShieldConfig;

        $this->assertNull($config->saasZoneId());
    }

    public function test_local_d1_database_name_falls_back_to_matching_worker_environment_database(): void
    {
        $root = $this->fakeWorkerRoot(<<<'TOML'
name = "verifysky-edge"

[[d1_databases]]
binding = "DB"
database_name = "VERIFY_SKY_PRODUCTION_DB"
database_id = "prod-id"

[env.staging]
name = "verifysky-edge-staging"

[[env.staging.d1_databases]]
binding = "DB"
database_name = "VERIFY_SKY_STAGING_DB_V2"
database_id = "staging-id"
TOML);

        Config::set('edgeshield.root', $root);
        Config::set('edgeshield.d1_mode', 'local');
        Config::set('edgeshield.worker_name', 'verifysky-edge-staging');
        Config::set('edgeshield.d1_database_name', 'VERIFY_SKY_STAGING_DB');
        Config::set('edgeshield.d1_database_id', '');

        $config = new EdgeShieldConfig;

        $this->assertSame('VERIFY_SKY_STAGING_DB_V2', $config->d1DatabaseName());
        $this->assertSame('staging', $config->wranglerEnvironmentName());
        $this->assertSame('staging-id', $config->d1DatabaseId());
    }

    private function fakeWorkerRoot(string $wranglerToml): string
    {
        $directory = storage_path('framework/testing/edge-config-'.bin2hex(random_bytes(6)));
        @mkdir($directory.'/src', 0777, true);
        file_put_contents($directory.'/wrangler.toml', $wranglerToml);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}
