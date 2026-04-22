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

    public function test_worker_script_name_defaults_to_production_script(): void
    {
        Config::set('edgeshield.worker_name', null);

        $config = new EdgeShieldConfig;

        $this->assertSame('verifysky-edge', $config->workerScriptName());
    }

    public function test_cloudflare_credentials_fall_back_to_dashboard_settings(): void
    {
        Config::set('edgeshield.cloudflare_api_token', '');
        Config::set('edgeshield.cloudflare_account_id', '');

        DashboardSetting::query()->create(['key' => 'cf_api_token', 'value' => 'token-from-settings']);
        DashboardSetting::query()->create(['key' => 'cf_account_id', 'value' => 'account-from-settings']);

        $config = new EdgeShieldConfig;

        $this->assertSame('token-from-settings', $config->cloudflareApiToken());
        $this->assertSame('account-from-settings', $config->cloudflareAccountId());
    }

    public function test_saas_zone_id_falls_back_to_dashboard_settings(): void
    {
        Config::set('edgeshield.saas_zone_id', '');

        DashboardSetting::query()->create(['key' => 'cf_zone_id', 'value' => 'zone-from-settings']);

        $config = new EdgeShieldConfig;

        $this->assertSame('zone-from-settings', $config->saasZoneId());
    }
}
