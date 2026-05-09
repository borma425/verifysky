<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cloudflare_usage_daily')) {
            return;
        }

        Schema::table('cloudflare_usage_daily', function (Blueprint $table): void {
            if (! Schema::hasColumn('cloudflare_usage_daily', 'pass_d1_writes')) {
                $table->unsignedBigInteger('pass_d1_writes')->default(0)->after('kv_write_bytes');
            }
            if (! Schema::hasColumn('cloudflare_usage_daily', 'pass_kv_writes')) {
                $table->unsignedBigInteger('pass_kv_writes')->default(0)->after('pass_d1_writes');
            }
            if (! Schema::hasColumn('cloudflare_usage_daily', 'pass_kv_reads')) {
                $table->unsignedBigInteger('pass_kv_reads')->default(0)->after('pass_kv_writes');
            }
            if (! Schema::hasColumn('cloudflare_usage_daily', 'pass_config_cache_hit')) {
                $table->unsignedBigInteger('pass_config_cache_hit')->default(0)->after('pass_kv_reads');
            }
            if (! Schema::hasColumn('cloudflare_usage_daily', 'pass_config_cache_miss')) {
                $table->unsignedBigInteger('pass_config_cache_miss')->default(0)->after('pass_config_cache_hit');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cloudflare_usage_daily')) {
            return;
        }

        Schema::table('cloudflare_usage_daily', function (Blueprint $table): void {
            foreach ([
                'pass_config_cache_miss',
                'pass_config_cache_hit',
                'pass_kv_reads',
                'pass_kv_writes',
                'pass_d1_writes',
            ] as $column) {
                if (Schema::hasColumn('cloudflare_usage_daily', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
