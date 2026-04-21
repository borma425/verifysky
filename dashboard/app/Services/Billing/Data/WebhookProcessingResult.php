<?php

namespace App\Services\Billing\Data;

readonly class WebhookProcessingResult
{
    public function __construct(
        public bool $ok,
        public ?string $eventId = null,
        public ?string $eventType = null,
        public ?string $subscriptionId = null,
        public ?int $tenantId = null,
        public ?string $error = null
    ) {}
}
