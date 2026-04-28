<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_domains', function (Blueprint $table) {
            $table->string('requested_domain', 255)->nullable()->after('hostname');
            $table->string('canonical_hostname', 255)->nullable()->after('requested_domain');
            $table->string('apex_mode', 40)->default('www_redirect')->after('canonical_hostname');
            $table->string('dns_provider', 40)->default('other')->after('apex_mode');
            $table->string('apex_redirect_status', 40)->nullable()->after('dns_provider');
            $table->timestamp('apex_redirect_checked_at')->nullable()->after('apex_redirect_status');

            $table->index(['tenant_id', 'requested_domain']);
            $table->index(['tenant_id', 'canonical_hostname']);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_domains', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'requested_domain']);
            $table->dropIndex(['tenant_id', 'canonical_hostname']);
            $table->dropColumn([
                'requested_domain',
                'canonical_hostname',
                'apex_mode',
                'dns_provider',
                'apex_redirect_status',
                'apex_redirect_checked_at',
            ]);
        });
    }
};
