<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantPlanGrantMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_plan_grants_table_and_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasTable('tenant_plan_grants'));
        $this->assertTrue(Schema::hasColumns('tenant_plan_grants', [
            'tenant_id',
            'granted_plan_key',
            'status',
            'starts_at',
            'ends_at',
            'granted_by_user_id',
            'revoked_by_user_id',
            'metadata_json',
        ]));

        $indexes = collect(DB::select("PRAGMA index_list('tenant_plan_grants')"))
            ->mapWithKeys(fn ($index): array => [$index->name => (int) $index->unique])
            ->all();

        $this->assertSame(0, $indexes['tenant_plan_grants_tenant_id_status_index'] ?? null);
        $this->assertSame(0, $indexes['tenant_plan_grants_tenant_id_ends_at_index'] ?? null);
    }
}
