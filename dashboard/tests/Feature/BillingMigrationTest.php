<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BillingMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->dropAllTables();
    }

    protected function tearDown(): void
    {
        $this->dropAllTables();
        parent::tearDown();
    }

    public function test_existing_tenants_are_backfilled_and_tenant_usage_indexes_exist(): void
    {
        $this->runMigration('2026_04_15_000001_create_saas_tenants_table.php');

        DB::table('tenants')->insert([
            'name' => 'Legacy Tenant',
            'slug' => 'legacy-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'settings' => null,
            'created_at' => '2026-03-10 12:00:00',
            'updated_at' => '2026-03-10 12:00:00',
        ]);

        $this->runMigration('2026_04_21_000001_add_billing_start_at_and_create_tenant_usage_table.php');

        $tenant = DB::table('tenants')->where('slug', 'legacy-tenant')->first();

        $this->assertSame('2026-03-10 12:00:00', $tenant->billing_start_at);
        $this->assertTrue(Schema::hasTable('tenant_usage'));

        $indexes = collect(DB::select("PRAGMA index_list('tenant_usage')"))
            ->mapWithKeys(fn (object $index): array => [$index->name => (int) $index->unique]);

        $this->assertSame(1, $indexes['tenant_usage_tenant_id_cycle_start_at_unique'] ?? null);
        $this->assertSame(0, $indexes['tenant_usage_tenant_id_cycle_end_at_index'] ?? null);
        $this->assertSame(0, $indexes['tenant_usage_tenant_id_quota_status_index'] ?? null);

        $foreignKeys = DB::select("PRAGMA foreign_key_list('tenant_usage')");
        $tenantForeignKey = collect($foreignKeys)->firstWhere('table', 'tenants');

        $this->assertNotNull($tenantForeignKey);
        $this->assertSame('CASCADE', $tenantForeignKey->on_delete);
    }

    public function test_new_tenants_can_be_created_without_explicit_billing_start_at(): void
    {
        $this->runMigration('2026_04_15_000001_create_saas_tenants_table.php');
        $this->runMigration('2026_04_21_000001_add_billing_start_at_and_create_tenant_usage_table.php');

        $tenant = Tenant::query()->create([
            'name' => 'Fresh Tenant',
            'slug' => 'fresh-tenant',
            'plan' => 'starter',
            'status' => 'active',
        ]);

        $this->assertNotNull($tenant->fresh()->billing_start_at);
    }

    public function test_batch_ten_migrations_add_usage_warning_column_and_admin_audit_table(): void
    {
        $this->runMigration('2026_04_15_000001_create_saas_tenants_table.php');
        $this->runMigration('2026_04_21_000001_add_billing_start_at_and_create_tenant_usage_table.php');
        $this->runMigration('2026_04_21_000002_add_usage_warning_sent_at_to_tenant_usage_table.php');
        $this->runMigration('2026_04_21_000003_create_admin_impersonation_events_table.php');

        $this->assertTrue(Schema::hasColumn('tenant_usage', 'usage_warning_sent_at'));
        $this->assertTrue(Schema::hasTable('admin_impersonation_events'));
    }

    private function runMigration(string $filename): void
    {
        $path = database_path('migrations/'.$filename);
        $migration = require $path;
        $migration->up();
    }

    private function dropAllTables(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropAllTables();
        Schema::enableForeignKeyConstraints();
    }
}
