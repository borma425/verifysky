<?php

namespace Tests\Feature;

use App\Jobs\PurgeRuntimeBundleCache;
use App\Models\PaymentWebhookEvent;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantSubscription;
use App\Models\TenantUsage;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PayPalWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_activation_webhook_updates_tenant_plan_and_resets_cycle_once(): void
    {
        Queue::fake();
        $tenant = $this->makeTenant();
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);
        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => TenantSubscription::PROVIDER_PAYPAL,
            'provider_subscription_id' => 'I-PENDING1',
            'plan_key' => 'growth',
            'provider_plan_id' => 'P-GROWTH',
            'status' => TenantSubscription::STATUS_PENDING_APPROVAL,
            'metadata_json' => ['custom_id' => 'vs|'.$tenant->id.'|growth|7'],
        ]);

        $this->configurePaypal();
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'token-123',
                'expires_in' => 3600,
            ], 200),
            'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'SUCCESS',
            ], 200),
            'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-PENDING1' => Http::response([
                'id' => 'I-PENDING1',
                'status' => 'ACTIVE',
                'plan_id' => 'P-GROWTH',
                'custom_id' => 'vs|'.$tenant->id.'|growth|7',
                'start_time' => '2026-05-01T00:00:00Z',
                'subscriber' => ['email_address' => 'owner@example.test'],
                'billing_info' => [
                    'last_payment' => ['time' => '2026-05-01T00:00:00Z'],
                    'next_billing_time' => '2026-06-01T00:00:00Z',
                ],
            ], 200),
        ]);

        $headers = $this->paypalHeaders();
        $payload = [
            'id' => 'WH-EVT-1',
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
            'resource' => [
                'id' => 'I-PENDING1',
                'custom_id' => 'vs|'.$tenant->id.'|growth|7',
                'plan_id' => 'P-GROWTH',
            ],
        ];

        $this->postJson(route('webhooks.payments.paypal'), $payload, $headers)->assertOk();
        $this->postJson(route('webhooks.payments.paypal'), $payload, $headers)->assertOk();

        $tenant->refresh();
        $this->assertSame('growth', $tenant->plan);

        $subscription = TenantSubscription::query()->where('provider_subscription_id', 'I-PENDING1')->sole();
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $subscription->status);
        $this->assertFalse($subscription->cancel_at_period_end);
        $this->assertSame(1, PaymentWebhookEvent::query()->count());
        $this->assertNotNull(PaymentWebhookEvent::query()->first()?->processed_at);
        $this->assertSame(1, TenantUsage::query()->where('tenant_id', $tenant->id)->count());

        Queue::assertPushed(PurgeRuntimeBundleCache::class, 1);
    }

    public function test_canceled_webhook_marks_subscription_for_period_end_without_downgrading_immediately(): void
    {
        Queue::fake();
        $tenant = $this->makeTenant('growth');
        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => TenantSubscription::PROVIDER_PAYPAL,
            'provider_subscription_id' => 'I-ACTIVE1',
            'plan_key' => 'growth',
            'provider_plan_id' => 'P-GROWTH',
            'status' => TenantSubscription::STATUS_ACTIVE,
            'current_period_starts_at' => '2026-05-01 00:00:00',
            'current_period_ends_at' => '2026-06-01 00:00:00',
        ]);

        $this->configurePaypal();
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'token-123',
                'expires_in' => 3600,
            ], 200),
            'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'SUCCESS',
            ], 200),
            'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-ACTIVE1' => Http::response([
                'id' => 'I-ACTIVE1',
                'status' => 'CANCELLED',
                'plan_id' => 'P-GROWTH',
                'subscriber' => ['email_address' => 'owner@example.test'],
                'billing_info' => [
                    'last_payment' => ['time' => '2026-05-01T00:00:00Z'],
                    'next_billing_time' => '2026-06-01T00:00:00Z',
                ],
            ], 200),
        ]);

        $payload = [
            'id' => 'WH-EVT-2',
            'event_type' => 'BILLING.SUBSCRIPTION.CANCELLED',
            'resource' => [
                'id' => 'I-ACTIVE1',
            ],
        ];

        $this->postJson(route('webhooks.payments.paypal'), $payload, $this->paypalHeaders())->assertOk();

        $tenant->refresh();
        $subscription = TenantSubscription::query()->where('provider_subscription_id', 'I-ACTIVE1')->sole();

        $this->assertSame('growth', $tenant->plan);
        $this->assertSame(TenantSubscription::STATUS_CANCELED, $subscription->status);
        $this->assertTrue($subscription->cancel_at_period_end);
        Queue::assertNothingPushed();
    }

    public function test_invalid_paypal_signature_is_rejected(): void
    {
        $this->configurePaypal();
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'token-123',
                'expires_in' => 3600,
            ], 200),
            'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'FAILURE',
            ], 200),
        ]);

        $response = $this->postJson(route('webhooks.payments.paypal'), [
            'id' => 'WH-BAD-1',
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
            'resource' => ['id' => 'I-BAD'],
        ], $this->paypalHeaders());

        $response->assertStatus(400);
        $this->assertSame(0, PaymentWebhookEvent::query()->count());
    }

    public function test_paypal_webhook_can_be_posted_without_csrf_token(): void
    {
        $this->withMiddleware(VerifyCsrfToken::class);
        $this->configurePaypal();
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'token-123',
                'expires_in' => 3600,
            ], 200),
            'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'FAILURE',
            ], 200),
        ]);

        $response = $this->postJson(route('webhooks.payments.paypal'), [
            'id' => 'WH-CSRF-1',
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
            'resource' => ['id' => 'I-CSRF'],
        ], $this->paypalHeaders());

        $response->assertStatus(400);
        $this->assertSame(0, PaymentWebhookEvent::query()->count());
    }

    private function makeTenant(string $plan = 'starter'): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Webhook Tenant',
            'slug' => 'webhook-tenant-'.$plan,
            'plan' => $plan,
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);
    }

    private function configurePaypal(): void
    {
        config()->set('services.paypal', [
            'mode' => 'sandbox',
            'client_id' => 'client-id',
            'secret' => 'secret',
            'webhook_id' => 'wh-123',
            'brand_name' => 'VerifySky',
            'plans' => [
                'growth' => 'P-GROWTH',
                'pro' => 'P-PRO',
                'business' => 'P-BUSINESS',
                'scale' => 'P-SCALE',
            ],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function paypalHeaders(): array
    {
        return [
            'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
            'PAYPAL-CERT-URL' => 'https://paypal.example/cert',
            'PAYPAL-TRANSMISSION-ID' => 'trans-1',
            'PAYPAL-TRANSMISSION-SIG' => 'sig-1',
            'PAYPAL-TRANSMISSION-TIME' => '2026-05-01T00:00:00Z',
        ];
    }
}
