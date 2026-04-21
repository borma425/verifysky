<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_tables_and_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasTable('tenant_subscriptions'));
        $this->assertTrue(Schema::hasTable('payment_webhook_events'));

        $this->assertTrue(Schema::hasColumns('tenant_subscriptions', [
            'tenant_id',
            'provider',
            'provider_subscription_id',
            'plan_key',
            'status',
            'current_period_ends_at',
            'cancel_at_period_end',
            'metadata_json',
        ]));

        $subscriptionIndexes = collect(DB::select("PRAGMA index_list('tenant_subscriptions')"))
            ->mapWithKeys(fn ($index): array => [$index->name => (int) $index->unique])
            ->all();

        $this->assertSame(1, $subscriptionIndexes['tenant_subscriptions_provider_provider_subscription_id_unique'] ?? null);
        $this->assertSame(0, $subscriptionIndexes['tenant_subscriptions_tenant_id_status_index'] ?? null);
        $this->assertSame(0, $subscriptionIndexes['tenant_subscriptions_tenant_id_current_period_ends_at_index'] ?? null);

        $eventIndexes = collect(DB::select("PRAGMA index_list('payment_webhook_events')"))
            ->mapWithKeys(fn ($index): array => [$index->name => (int) $index->unique])
            ->all();

        $this->assertSame(1, $eventIndexes['payment_webhook_events_provider_provider_event_id_unique'] ?? null);
    }
}
