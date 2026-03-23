<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trap_leads', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('domain', 255);
            $table->string('company', 190)->nullable();
            $table->text('notes')->nullable();
            $table->string('source', 80)->default('website');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['created_at']);
            $table->index(['email']);
            $table->index(['domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trap_leads');
    }
};

