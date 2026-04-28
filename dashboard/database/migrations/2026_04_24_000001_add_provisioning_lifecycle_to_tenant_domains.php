<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_domains', function (Blueprint $table) {
            $table->string('provisioning_status', 40)->default('active')->after('ssl_status');
            $table->text('provisioning_error')->nullable()->after('provisioning_status');
            $table->json('provisioning_payload')->nullable()->after('provisioning_error');
            $table->timestamp('provisioning_started_at')->nullable()->after('provisioning_payload');
            $table->timestamp('provisioning_finished_at')->nullable()->after('provisioning_started_at');
            $table->index(['tenant_id', 'provisioning_status']);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_domains', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'provisioning_status']);
            $table->dropColumn([
                'provisioning_status',
                'provisioning_error',
                'provisioning_payload',
                'provisioning_started_at',
                'provisioning_finished_at',
            ]);
        });
    }
};
