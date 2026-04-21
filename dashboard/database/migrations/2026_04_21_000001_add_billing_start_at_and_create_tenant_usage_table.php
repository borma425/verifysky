<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! Schema::hasColumn('tenants', 'billing_start_at')) {
            if ($driver === 'sqlite') {
                Schema::table('tenants', function (Blueprint $table) {
                    $table->timestamp('billing_start_at')->nullable()->after('status');
                });

                DB::table('tenants')->update([
                    'billing_start_at' => DB::raw('created_at'),
                ]);

                Schema::table('tenants', function (Blueprint $table) {
                    $table->timestamp('billing_start_at')
                        ->default(DB::raw('CURRENT_TIMESTAMP'))
                        ->after('status')
                        ->change();
                });
            } else {
                Schema::table('tenants', function (Blueprint $table) {
                    $table->dateTime('billing_start_at')
                        ->default(DB::raw('CURRENT_TIMESTAMP'))
                        ->after('status');
                });
            }
        }

        if (Schema::hasColumn('tenants', 'billing_start_at')) {
            DB::table('tenants')->whereNull('billing_start_at')->update([
                'billing_start_at' => DB::raw('created_at'),
            ]);
        }

        if (! Schema::hasTable('tenant_usage')) {
            Schema::create('tenant_usage', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->dateTime('cycle_start_at');
                $table->dateTime('cycle_end_at');
                $table->unsignedBigInteger('protected_sessions_used')->default(0);
                $table->unsignedBigInteger('bot_requests_used')->default(0);
                $table->string('quota_status', 40)->default('active');
                $table->dateTime('last_reconciled_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'cycle_start_at']);
                $table->index(['tenant_id', 'cycle_end_at']);
                $table->index(['tenant_id', 'quota_status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_usage');

        if (Schema::hasColumn('tenants', 'billing_start_at')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropColumn('billing_start_at');
            });
        }
    }
};
