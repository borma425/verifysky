<?php

namespace App\Http\Controllers;

use App\Services\Billing\Contracts\PaymentGatewayInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function __construct(private readonly PaymentGatewayInterface $payments) {}

    public function paypal(Request $request): JsonResponse
    {
        $result = $this->payments->handleWebhook($request);
        $status = $result->ok
            ? 200
            : (str_contains(strtolower((string) $result->error), 'signature') ? 400 : 422);

        return response()->json([
            'ok' => $result->ok,
            'event_id' => $result->eventId,
            'event_type' => $result->eventType,
            'subscription_id' => $result->subscriptionId,
            'tenant_id' => $result->tenantId,
            'error' => $result->error,
        ], $status);
    }
}
