<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('hostname', 255)->unique();
            $table->string('cname_target', 255)->default('customers.verifysky.com');
            $table->string('cloudflare_custom_hostname_id', 128)->nullable()->index();
            $table->string('hostname_status', 40)->default('pending');
            $table->string('ssl_status', 40)->default('pending');
            $table->string('security_mode', 40)->default('balanced');
            $table->boolean('force_captcha')->default(false);
            $table->json('ownership_verification')->nullable();
            $table->json('thresholds')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'hostname_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
    }
};
