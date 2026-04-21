<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_impersonation_events')) {
            return;
        }

        Schema::create('admin_impersonation_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_user_id')->nullable()->index();
            $table->string('admin_email', 190)->nullable();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('route_action', 255);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_impersonation_events');
    }
};
