<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_usage') || Schema::hasColumn('tenant_usage', 'usage_warning_sent_at')) {
            return;
        }

        Schema::table('tenant_usage', function (Blueprint $table): void {
            $table->timestamp('usage_warning_sent_at')->nullable()->after('last_reconciled_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenant_usage') || ! Schema::hasColumn('tenant_usage', 'usage_warning_sent_at')) {
            return;
        }

        Schema::table('tenant_usage', function (Blueprint $table): void {
            $table->dropColumn('usage_warning_sent_at');
        });
    }
};
