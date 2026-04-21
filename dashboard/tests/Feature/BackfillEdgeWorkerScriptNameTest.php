<?php

namespace Tests\Feature;

use App\Models\DashboardSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillEdgeWorkerScriptNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_backfills_legacy_worker_script_names(): void
    {
        DashboardSetting::query()->create([
            'key' => 'worker_script_name',
            'value' => 'verifysky-edge-staging',
        ]);

        $migration = require database_path('migrations/2026_04_22_000003_backfill_edge_worker_script_name.php');
        $migration->up();

        $this->assertSame(
            'verifysky-edge',
            DashboardSetting::query()->where('key', 'worker_script_name')->value('value')
        );
    }

    public function test_migration_preserves_custom_worker_script_names(): void
    {
        DashboardSetting::query()->create([
            'key' => 'worker_script_name',
            'value' => 'custom-enterprise-worker',
        ]);

        $migration = require database_path('migrations/2026_04_22_000003_backfill_edge_worker_script_name.php');
        $migration->up();

        $this->assertSame(
            'custom-enterprise-worker',
            DashboardSetting::query()->where('key', 'worker_script_name')->value('value')
        );
    }
}
