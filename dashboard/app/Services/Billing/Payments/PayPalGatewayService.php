<?php

namespace App\Services\Billing\Payments;

use App\Actions\Billing\ForceResetTenantBillingCycleAction;
use App\Models\PaymentWebhookEvent;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Billing\BillingPlanCatalogService;
use App\Services\Billing\Contracts\PaymentGatewayInterface;
use App\Services\Billing\Data\CheckoutSessionResult;
use App\Services\Billing\Data\PaymentActionResult;
use App\Services\Billing\Data\WebhookProcessingResult;
use App\Services\Billing\TenantSubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class PayPalGatewayService implements PaymentGatewayInterface
{
    public function __construct(
        private readonly BillingPlanCatalogService $planCatalog,
        private readonly TenantSubscriptionService $subscriptions,
        private readonly ForceResetTenantBillingCycleAction $forceResetTenantBillingCycle
    ) {}

    public function createCheckoutSession(
        Tenant $tenant,
        User $buyer,
        string $planKey,
        string $successUrl,
        string $cancelUrl
    ): CheckoutSessionResult {
        if (! $this->subscriptions->storageReady()) {
            return new CheckoutSessionResult(false, error: 'Billing storage is not ready.');
        }

        $plan = $this->planCatalog->plan($planKey);
        if ($plan === null) {
            return new CheckoutSessionResult(false, error: 'This plan is not available for checkout.');
        }

        $providerPlanId = $plan['provider_plan_id'] ?? null;
        if (! is_string($providerPlanId) || trim($providerPlanId) === '') {
            return new CheckoutSessionResult(false, error: 'PayPal is not configured for this plan.');
        }

        $token = $this->accessToken();
        if ($token === null) {
            return new CheckoutSessionResult(false, error: 'Unable to authenticate with PayPal.');
        }

        $customId = $this->buildCustomId($tenant, $plan['key'], $buyer);
        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->post($this->baseUrl().'/v1/billing/subscriptions', [
                'plan_id' => $providerPlanId,
                'custom_id' => $customId,
                'application_context' => [
                    'brand_name' => (string) config('services.paypal.brand_name', 'VerifySky'),
                    'user_action' => 'SUBSCRIBE_NOW',
                    'shipping_preference' => 'NO_SHIPPING',
                    'return_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                ],
            ]);

        if (! $response->successful()) {
            return new CheckoutSessionResult(false, error: 'PayPal checkout could not be created.');
        }

        $payload = $response->json();
        $subscriptionId = trim((string) ($payload['id'] ?? ''));
        $approveUrl = $this->linkHref($payload['links'] ?? [], 'approve');

        if ($subscriptionId === '' || $approveUrl === null) {
            return new CheckoutSessionResult(false, error: 'PayPal did not return a valid approval URL.');
        }

        TenantSubscription::query()->updateOrCreate(
            [
                'provider' => TenantSubscription::PROVIDER_PAYPAL,
                'provider_subscription_id' => $subscriptionId,
            ],
            [
                'tenant_id' => $tenant->getKey(),
                'plan_key' => $plan['key'],
                'provider_plan_id' => $providerPlanId,
                'status' => TenantSubscription::STATUS_PENDING_APPROVAL,
                'payer_email' => $buyer->email,
                'cancel_at_period_end' => false,
                'last_webhook_event_id' => null,
                'metadata_json' => [
                    'custom_id' => $customId,
                    'buyer_user_id' => $buyer->getKey(),
                    'buyer_email' => $buyer->email,
                ],
            ]
        );

        return new CheckoutSessionResult(true, $approveUrl, $subscriptionId);
    }

    public function cancelSubscription(TenantSubscription $subscription): PaymentActionResult
    {
        if (! $this->subscriptions->storageReady()) {
            return new PaymentActionResult(false, error: 'Billing storage is not ready.');
        }

        if ($subscription->provider !== TenantSubscription::PROVIDER_PAYPAL) {
            return new PaymentActionResult(false, error: 'Unsupported payment provider.');
        }

        if ($subscription->status === TenantSubscription::STATUS_CANCELED
            || $subscription->status === TenantSubscription::STATUS_EXPIRED) {
            return new PaymentActionResult(true, 'Subscription is already canceled.', $subscription->provider_subscription_id);
        }

        $token = $this->accessToken();
        if ($token === null) {
            return new PaymentActionResult(false, error: 'Unable to authenticate with PayPal.');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->post($this->baseUrl().'/v1/billing/subscriptions/'.$subscription->provider_subscription_id.'/cancel', [
                'reason' => 'Canceled from VerifySky billing portal.',
            ]);

        if (! $response->successful() && $response->status() !== 204) {
            return new PaymentActionResult(false, error: 'PayPal could not cancel the subscription.');
        }

        $subscription->forceFill([
            'status' => TenantSubscription::STATUS_CANCELED,
            'cancel_at_period_end' => true,
        ])->save();

        return new PaymentActionResult(
            true,
            'Subscription will end at the close of the current billing period.',
            $subscription->provider_subscription_id
        );
    }

    public function handleWebhook(Request $request): WebhookProcessingResult
    {
        if (! $this->subscriptions->storageReady()) {
            return new WebhookProcessingResult(false, error: 'Billing storage is not ready.');
        }

        if (! $this->verifyWebhookSignature($request)) {
            return new WebhookProcessingResult(false, error: 'Invalid PayPal webhook signature.');
        }

        $payload = $request->json()->all();
        $eventId = trim((string) ($payload['id'] ?? ''));
        $eventType = trim((string) ($payload['event_type'] ?? ''));
        if ($eventId === '' || $eventType === '') {
            return new WebhookProcessingResult(false, error: 'Webhook payload is missing required fields.');
        }

        $duplicate = $this->subscriptions->webhookEventAlreadyProcessed(TenantSubscription::PROVIDER_PAYPAL, $eventId);
        if ($duplicate instanceof PaymentWebhookEvent) {
            return new WebhookProcessingResult(true, $eventId, $eventType);
        }

        $event = PaymentWebhookEvent::query()->firstOrCreate(
            [
                'provider' => TenantSubscription::PROVIDER_PAYPAL,
                'provider_event_id' => $eventId,
            ],
            [
                'event_type' => $eventType,
                'payload_json' => $payload,
            ]
        );

        if ($event->processed_at !== null) {
            return new WebhookProcessingResult(true, $eventId, $eventType);
        }

        try {
            $result = $this->processWebhookEvent($event, $payload, $eventType, $eventId);
            $event->forceFill([
                'processed_at' => CarbonImmutable::now('UTC'),
                'processing_error' => null,
            ])->save();

            return $result;
        } catch (Throwable $exception) {
            $event->forceFill([
                'processing_error' => $exception->getMessage(),
            ])->save();

            return new WebhookProcessingResult(false, $eventId, $eventType, error: $exception->getMessage());
        }
    }

    private function processWebhookEvent(
        PaymentWebhookEvent $event,
        array $payload,
        string $eventType,
        string $eventId
    ): WebhookProcessingResult {
        $resource = is_array($payload['resource'] ?? null) ? $payload['resource'] : [];
        $providerSubscriptionId = $this->extractProviderSubscriptionId($resource, $eventType);
        if ($providerSubscriptionId === '') {
            throw new \RuntimeException('Webhook is missing a PayPal subscription identifier.');
        }

        $parsedCustomId = $this->parseCustomId((string) ($resource['custom_id'] ?? ''));
        $subscription = $this->subscriptions->findByProviderSubscriptionId(
            TenantSubscription::PROVIDER_PAYPAL,
            $providerSubscriptionId
        );
        $previousStatus = $subscription?->status;
        $previousPeriodStartAt = $this->dateValue($subscription?->getAttribute('current_period_starts_at'));

        if ($subscription === null && $parsedCustomId === null) {
            throw new \RuntimeException('Unable to map the webhook to a VerifySky tenant.');
        }

        $tenant = $subscription?->tenant;
        if ($tenant === null && $parsedCustomId !== null) {
            $tenant = Tenant::query()->find($parsedCustomId['tenant_id']);
        }
        if (! $tenant instanceof Tenant) {
            throw new \RuntimeException('The webhook referenced a tenant that does not exist.');
        }

        if ($subscription instanceof TenantSubscription) {
            $planKey = $subscription->plan_key;
        } elseif ($parsedCustomId !== null) {
            $planKey = $parsedCustomId['plan_key'];
        } else {
            throw new \RuntimeException('Unable to map the webhook to a VerifySky tenant.');
        }

        if ($this->planCatalog->plan($planKey) === null) {
            throw new \RuntimeException('The webhook referenced an unsupported plan.');
        }

        $details = $this->fetchSubscriptionDetails($providerSubscriptionId);
        $normalizedEvent = $this->normalizeWebhookEvent($eventType);

        $subscription = TenantSubscription::query()->updateOrCreate(
            [
                'provider' => TenantSubscription::PROVIDER_PAYPAL,
                'provider_subscription_id' => $providerSubscriptionId,
            ],
            [
                'tenant_id' => $tenant->getKey(),
                'plan_key' => $planKey,
                'provider_plan_id' => $this->extractProviderPlanId($details, $resource) ?? $this->planCatalog->providerPlanIdFor($planKey),
                'status' => $this->statusForNormalizedEvent($normalizedEvent),
                'payer_email' => $this->extractPayerEmail($details, $resource, $subscription?->payer_email),
                'current_period_starts_at' => $this->extractPeriodStartAt($details, $this->dateString($subscription?->current_period_starts_at)),
                'current_period_ends_at' => $this->extractPeriodEndAt($details, $this->dateString($subscription?->current_period_ends_at)),
                'cancel_at_period_end' => in_array($normalizedEvent, ['canceled', 'suspended'], true),
                'last_webhook_event_id' => $eventId,
                'metadata_json' => array_filter([
                    'custom_id' => $details['custom_id'] ?? ($resource['custom_id'] ?? null),
                    'subscriber' => $details['subscriber'] ?? ($resource['subscriber'] ?? null),
                ], static fn ($value): bool => $value !== null),
            ]
        );

        if ($normalizedEvent === 'ignored') {
            return new WebhookProcessingResult(true, $eventId, $eventType, $subscription->provider_subscription_id, (int) $tenant->getKey());
        }

        if ($normalizedEvent === 'active') {
            $this->activateSubscription(
                $subscription,
                $tenant,
                $eventId,
                $previousStatus,
                $previousPeriodStartAt
            );
        }

        return new WebhookProcessingResult(
            true,
            $eventId,
            $eventType,
            $subscription->provider_subscription_id,
            (int) $tenant->getKey()
        );
    }

    private function activateSubscription(
        TenantSubscription $subscription,
        Tenant $tenant,
        string $eventId,
        ?string $previousStatus,
        ?CarbonImmutable $previousPeriodStartAt
    ): void {
        $shouldReset = $tenant->plan !== $subscription->plan_key
            || $previousStatus !== TenantSubscription::STATUS_ACTIVE;

        $previousSubscription = $this->subscriptions
            ->activeReplacementCandidates($subscription)
            ->latest('updated_at')
            ->get();

        foreach ($previousSubscription as $otherSubscription) {
            $this->cancelSubscription($otherSubscription);
            $otherSubscription->forceFill([
                'status' => TenantSubscription::STATUS_CANCELED,
                'cancel_at_period_end' => false,
                'current_period_ends_at' => CarbonImmutable::now('UTC'),
                'last_webhook_event_id' => $eventId,
            ])->save();
            $shouldReset = true;
        }

        $currentPeriodStartAt = $this->dateValue($subscription->getAttribute('current_period_starts_at'));
        if ($currentPeriodStartAt !== null && $previousPeriodStartAt !== null) {
            if ($currentPeriodStartAt->gt($previousPeriodStartAt)) {
                $shouldReset = true;
            }
        } elseif ($currentPeriodStartAt !== null) {
            $shouldReset = true;
        }

        $tenant->forceFill([
            'plan' => $subscription->plan_key,
        ])->save();

        if ($shouldReset) {
            $this->forceResetTenantBillingCycle->execute($tenant, $currentPeriodStartAt ?? CarbonImmutable::now('UTC'));
        }
    }

    private function verifyWebhookSignature(Request $request): bool
    {
        $token = $this->accessToken();
        $webhookId = trim((string) config('services.paypal.webhook_id', ''));
        if ($token === null || $webhookId === '') {
            return false;
        }

        $payload = $request->json()->all();
        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->post($this->baseUrl().'/v1/notifications/verify-webhook-signature', [
                'auth_algo' => (string) $request->header('PAYPAL-AUTH-ALGO'),
                'cert_url' => (string) $request->header('PAYPAL-CERT-URL'),
                'transmission_id' => (string) $request->header('PAYPAL-TRANSMISSION-ID'),
                'transmission_sig' => (string) $request->header('PAYPAL-TRANSMISSION-SIG'),
                'transmission_time' => (string) $request->header('PAYPAL-TRANSMISSION-TIME'),
                'webhook_id' => $webhookId,
                'webhook_event' => $payload,
            ]);

        return $response->successful()
            && strtoupper((string) ($response->json('verification_status') ?? '')) === 'SUCCESS';
    }

    private function fetchSubscriptionDetails(string $providerSubscriptionId): array
    {
        $token = $this->accessToken();
        if ($token === null) {
            throw new \RuntimeException('Unable to authenticate with PayPal.');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->get($this->baseUrl().'/v1/billing/subscriptions/'.$providerSubscriptionId);

        if (! $response->successful()) {
            throw new \RuntimeException('PayPal subscription details could not be retrieved.');
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    private function accessToken(): ?string
    {
        $clientId = trim((string) config('services.paypal.client_id', ''));
        $secret = trim((string) config('services.paypal.secret', ''));
        if ($clientId === '' || $secret === '') {
            return null;
        }

        $cacheKey = 'paypal_access_token:'.md5($this->baseUrl().$clientId);

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::withBasicAuth($clientId, $secret)
            ->asForm()
            ->acceptJson()
            ->post($this->baseUrl().'/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        if (! $response->successful()) {
            return null;
        }

        $token = trim((string) ($response->json('access_token') ?? ''));
        if ($token === '') {
            return null;
        }

        $expiresIn = max(60, ((int) ($response->json('expires_in') ?? 3000)) - 60);
        Cache::put($cacheKey, $token, $expiresIn);

        return $token;
    }

    private function baseUrl(): string
    {
        return config('services.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    private function buildCustomId(Tenant $tenant, string $planKey, User $buyer): string
    {
        return sprintf('vs|%s|%s|%s', $tenant->getKey(), $planKey, $buyer->getKey());
    }

    /**
     * @return array{tenant_id:int,plan_key:string,buyer_user_id:int}|null
     */
    private function parseCustomId(string $customId): ?array
    {
        $parts = explode('|', trim($customId));
        if (count($parts) !== 4 || $parts[0] !== 'vs') {
            return null;
        }

        if (! ctype_digit($parts[1]) || ! ctype_digit($parts[3])) {
            return null;
        }

        return [
            'tenant_id' => (int) $parts[1],
            'plan_key' => strtolower(trim($parts[2])),
            'buyer_user_id' => (int) $parts[3],
        ];
    }

    private function extractProviderSubscriptionId(array $resource, string $eventType): string
    {
        if ($eventType === 'PAYMENT.SALE.COMPLETED') {
            return trim((string) ($resource['billing_agreement_id'] ?? $resource['id'] ?? ''));
        }

        return trim((string) ($resource['id'] ?? ''));
    }

    private function normalizeWebhookEvent(string $eventType): string
    {
        return match ($eventType) {
            'BILLING.SUBSCRIPTION.ACTIVATED',
            'BILLING.SUBSCRIPTION.RE-ACTIVATED',
            'PAYMENT.SALE.COMPLETED' => 'active',
            'BILLING.SUBSCRIPTION.CANCELLED',
            'BILLING.SUBSCRIPTION.CANCELED' => 'canceled',
            'BILLING.SUBSCRIPTION.SUSPENDED',
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => 'suspended',
            default => 'ignored',
        };
    }

    private function statusForNormalizedEvent(string $normalizedEvent): string
    {
        return match ($normalizedEvent) {
            'active' => TenantSubscription::STATUS_ACTIVE,
            'canceled' => TenantSubscription::STATUS_CANCELED,
            'suspended' => TenantSubscription::STATUS_SUSPENDED,
            default => TenantSubscription::STATUS_PENDING_APPROVAL,
        };
    }

    private function extractProviderPlanId(array $details, array $resource): ?string
    {
        $planId = trim((string) ($details['plan_id'] ?? $resource['plan_id'] ?? ''));

        return $planId !== '' ? $planId : null;
    }

    private function extractPayerEmail(array $details, array $resource, ?string $fallback = null): ?string
    {
        $email = trim((string) (
            $details['subscriber']['email_address']
            ?? $resource['subscriber']['email_address']
            ?? $fallback
            ?? ''
        ));

        return $email !== '' ? $email : null;
    }

    private function extractPeriodStartAt(array $details, ?string $fallback = null): ?CarbonImmutable
    {
        $value = trim((string) (
            $details['billing_info']['last_payment']['time']
            ?? $details['start_time']
            ?? $fallback
            ?? ''
        ));

        return $value !== '' ? CarbonImmutable::parse($value, 'UTC')->utc() : null;
    }

    private function extractPeriodEndAt(array $details, ?string $fallback = null): ?CarbonImmutable
    {
        $value = trim((string) (
            $details['billing_info']['next_billing_time']
            ?? $fallback
            ?? ''
        ));

        return $value !== '' ? CarbonImmutable::parse($value, 'UTC')->utc() : null;
    }

    private function dateString(mixed $value): ?string
    {
        return $value !== null
            ? CarbonImmutable::parse((string) $value, 'UTC')->utc()->toIso8601String()
            : null;
    }

    private function dateValue(mixed $value): ?CarbonImmutable
    {
        return $value !== null ? CarbonImmutable::parse((string) $value, 'UTC')->utc() : null;
    }

    private function linkHref(array $links, string $rel): ?string
    {
        foreach ($links as $link) {
            if (! is_array($link) || ($link['rel'] ?? null) !== $rel) {
                continue;
            }

            $href = trim((string) ($link['href'] ?? ''));
            if ($href !== '') {
                return $href;
            }
        }

        return null;
    }
}
