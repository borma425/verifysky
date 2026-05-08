<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'is_vip')) {
            Schema::table('tenants', function (Blueprint $table): void {
                $table->boolean('is_vip')->default(false)->after('status');
            });
        }

        if (! Schema::hasTable('cloudflare_usage_daily')) {
            Schema::create('cloudflare_usage_daily', function (Blueprint $table): void {
                $table->id();
                $table->date('usage_date');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('domain_name', 255);
                $table->string('environment', 40)->default('production');
                $table->string('outcome', 40)->default('legacy');
                $table->unsignedBigInteger('requests')->default(0);
                $table->unsignedBigInteger('d1_rows_read')->default(0);
                $table->unsignedBigInteger('d1_rows_written')->default(0);
                $table->unsignedBigInteger('d1_query_count')->default(0);
                $table->unsignedBigInteger('kv_reads')->default(0);
                $table->unsignedBigInteger('kv_writes')->default(0);
                $table->unsignedBigInteger('kv_deletes')->default(0);
                $table->unsignedBigInteger('kv_lists')->default(0);
                $table->unsignedBigInteger('kv_write_bytes')->default(0);
                $table->dateTime('last_synced_at')->nullable();
                $table->timestamps();

                $table->unique(['usage_date', 'tenant_id', 'domain_name', 'environment', 'outcome'], 'cf_usage_daily_identity');
                $table->index(['tenant_id', 'usage_date']);
                $table->index(['environment', 'usage_date', 'outcome'], 'cf_usage_daily_outcome_idx');
            });
        }

        if (! Schema::hasTable('cloudflare_cost_daily')) {
            Schema::create('cloudflare_cost_daily', function (Blueprint $table): void {
                $table->id();
                $table->date('usage_date');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('domain_name', 255);
                $table->string('environment', 40)->default('production');
                $table->string('outcome', 40)->default('legacy');
                $table->decimal('workers_requests_cost_usd', 14, 6)->default(0);
                $table->decimal('workers_cpu_cost_usd', 14, 6)->default(0);
                $table->decimal('d1_cost_usd', 14, 6)->default(0);
                $table->decimal('kv_cost_usd', 14, 6)->default(0);
                $table->decimal('wae_cost_usd', 14, 6)->default(0);
                $table->decimal('total_estimated_cost_usd', 14, 6)->default(0);
                $table->decimal('final_reconciled_cost_usd', 14, 6)->nullable();
                $table->dateTime('last_synced_at')->nullable();
                $table->timestamps();

                $table->unique(['usage_date', 'tenant_id', 'domain_name', 'environment', 'outcome'], 'cf_cost_daily_identity');
                $table->index(['tenant_id', 'usage_date']);
                $table->index(['environment', 'usage_date', 'outcome'], 'cf_cost_daily_outcome_idx');
            });
        }

        if (! Schema::hasTable('cloudflare_billing_snapshots')) {
            Schema::create('cloudflare_billing_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->date('period_start');
                $table->date('period_end');
                $table->string('environment', 40)->default('production');
                $table->string('source', 80)->default('manual');
                $table->string('resource', 120)->default('cloudflare_total');
                $table->string('currency', 3)->default('USD');
                $table->decimal('amount_usd', 14, 6)->default(0);
                $table->decimal('usage_quantity', 20, 6)->default(0);
                $table->json('raw_payload')->nullable();
                $table->dateTime('final_reconciled_at')->nullable();
                $table->timestamps();

                $table->unique(['period_start', 'period_end', 'environment', 'source', 'resource'], 'cf_billing_snapshot_identity');
                $table->index(['environment', 'period_start', 'period_end'], 'cf_billing_period_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cloudflare_billing_snapshots');
        Schema::dropIfExists('cloudflare_cost_daily');
        Schema::dropIfExists('cloudflare_usage_daily');

        if (Schema::hasColumn('tenants', 'is_vip')) {
            Schema::table('tenants', function (Blueprint $table): void {
                $table->dropColumn('is_vip');
            });
        }
    }
};
