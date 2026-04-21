<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_plan_grants')) {
            Schema::create('tenant_plan_grants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('granted_plan_key', 80);
                $table->string('source', 40)->default('manual');
                $table->string('status', 40)->default('active');
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('reason')->nullable();
                $table->dateTime('revoked_at')->nullable();
                $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'ends_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_plan_grants');
    }
};
