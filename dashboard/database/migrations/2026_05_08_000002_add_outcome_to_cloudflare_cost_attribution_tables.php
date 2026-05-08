<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addOutcomeColumn('cloudflare_usage_daily');
        $this->addOutcomeColumn('cloudflare_cost_daily');
        $this->rebuildIdentity('cloudflare_usage_daily', 'cf_usage_daily_identity', 'cf_usage_daily_outcome_idx');
        $this->rebuildIdentity('cloudflare_cost_daily', 'cf_cost_daily_identity', 'cf_cost_daily_outcome_idx');
    }

    public function down(): void
    {
        $this->dropIdentity('cloudflare_usage_daily', 'cf_usage_daily_identity', 'cf_usage_daily_outcome_idx');
        $this->dropIdentity('cloudflare_cost_daily', 'cf_cost_daily_identity', 'cf_cost_daily_outcome_idx');

        if (Schema::hasColumn('cloudflare_usage_daily', 'outcome')) {
            Schema::table('cloudflare_usage_daily', function (Blueprint $table): void {
                $table->dropColumn('outcome');
            });
        }

        if (Schema::hasColumn('cloudflare_cost_daily', 'outcome')) {
            Schema::table('cloudflare_cost_daily', function (Blueprint $table): void {
                $table->dropColumn('outcome');
            });
        }

        if (Schema::hasTable('cloudflare_usage_daily')) {
            Schema::table('cloudflare_usage_daily', function (Blueprint $table): void {
                $table->unique(['usage_date', 'tenant_id', 'domain_name', 'environment'], 'cf_usage_daily_identity');
            });
        }

        if (Schema::hasTable('cloudflare_cost_daily')) {
            Schema::table('cloudflare_cost_daily', function (Blueprint $table): void {
                $table->unique(['usage_date', 'tenant_id', 'domain_name', 'environment'], 'cf_cost_daily_identity');
            });
        }
    }

    private function addOutcomeColumn(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'outcome')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->string('outcome', 40)->default('legacy')->after('environment');
        });
    }

    private function rebuildIdentity(string $tableName, string $indexName, string $outcomeIndexName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'outcome')) {
            return;
        }

        $this->dropIdentity($tableName, $indexName, $outcomeIndexName);

        Schema::table($tableName, function (Blueprint $table) use ($indexName, $outcomeIndexName): void {
            $table->unique(['usage_date', 'tenant_id', 'domain_name', 'environment', 'outcome'], $indexName);
            $table->index(['environment', 'usage_date', 'outcome'], $outcomeIndexName);
        });
    }

    private function dropIdentity(string $tableName, string $indexName, string $outcomeIndexName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS '.$indexName);
            DB::statement('DROP INDEX IF EXISTS '.$outcomeIndexName);

            return;
        }

        try {
            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->dropUnique($indexName);
            });
        } catch (Throwable) {
            // The fresh migration already creates the outcome-aware identity.
        }

        try {
            Schema::table($tableName, function (Blueprint $table) use ($outcomeIndexName): void {
                $table->dropIndex($outcomeIndexName);
            });
        } catch (Throwable) {
            // Older installations do not have the outcome lookup index yet.
        }
    }
};
