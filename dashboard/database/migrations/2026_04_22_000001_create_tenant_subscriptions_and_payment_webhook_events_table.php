<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_subscriptions')) {
            Schema::create('tenant_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('provider', 40);
                $table->string('provider_subscription_id', 191);
                $table->string('plan_key', 80);
                $table->string('provider_plan_id', 191)->nullable();
                $table->string('status', 40);
                $table->string('payer_email', 190)->nullable();
                $table->dateTime('current_period_starts_at')->nullable();
                $table->dateTime('current_period_ends_at')->nullable();
                $table->boolean('cancel_at_period_end')->default(false);
                $table->string('last_webhook_event_id', 191)->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'provider_subscription_id']);
                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'current_period_ends_at']);
            });
        }

        if (! Schema::hasTable('payment_webhook_events')) {
            Schema::create('payment_webhook_events', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 40);
                $table->string('provider_event_id', 191);
                $table->string('event_type', 120);
                $table->json('payload_json');
                $table->dateTime('processed_at')->nullable();
                $table->text('processing_error')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'provider_event_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('tenant_subscriptions');
    }
};
