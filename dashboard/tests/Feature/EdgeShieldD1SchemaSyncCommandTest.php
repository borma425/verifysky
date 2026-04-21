<?php

namespace Tests\Feature;

use App\Services\EdgeShield\D1SchemaSyncService;
use Mockery;
use Tests\TestCase;

class EdgeShieldD1SchemaSyncCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_command_runs_schema_sync_service(): void
    {
        $service = Mockery::mock(D1SchemaSyncService::class);
        $service->shouldReceive('sync')->once()->andReturn([
            'ok' => true,
            'changes' => ['Added column domain_configs.security_mode'],
        ]);
        $this->app->instance(D1SchemaSyncService::class, $service);

        $this->artisan('edgeshield:d1:schema-sync')
            ->expectsOutput('EdgeShield D1 schema sync completed.')
            ->expectsOutput('Changes applied: 1')
            ->expectsOutput('- Added column domain_configs.security_mode')
            ->assertSuccessful();
    }
}
