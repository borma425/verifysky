<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('domain_asset_histories')) {
            Schema::create('domain_asset_histories', function (Blueprint $table) {
                $table->id();
                $table->string('asset_key', 255)->unique();
                $table->string('asset_type', 40);
                $table->string('registrable_domain', 255)->nullable()->index();
                $table->string('hostname', 255)->nullable()->index();
                $table->dateTime('pro_trial_granted_at')->nullable();
                $table->unsignedBigInteger('pro_trial_tenant_id')->nullable()->index();
                $table->unsignedBigInteger('pro_trial_grant_id')->nullable()->index();
                $table->dateTime('quarantined_until')->nullable()->index();
                $table->dateTime('last_removed_at')->nullable();
                $table->unsignedBigInteger('last_removed_tenant_id')->nullable()->index();
                $table->string('last_removal_reason', 80)->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_asset_histories');
    }
};
