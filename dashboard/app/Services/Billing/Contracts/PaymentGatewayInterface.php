<?php

namespace App\Services\Billing\Contracts;

use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Billing\Data\CheckoutSessionResult;
use App\Services\Billing\Data\PaymentActionResult;
use App\Services\Billing\Data\WebhookProcessingResult;
use Illuminate\Http\Request;

interface PaymentGatewayInterface
{
    public function createCheckoutSession(
        Tenant $tenant,
        User $buyer,
        string $planKey,
        string $successUrl,
        string $cancelUrl
    ): CheckoutSessionResult;

    public function cancelSubscription(TenantSubscription $subscription): PaymentActionResult;

    public function handleWebhook(Request $request): WebhookProcessingResult;
}
