<?php

namespace App\Services\Billing\Data;

readonly class PaymentActionResult
{
    public function __construct(
        public bool $ok,
        public ?string $message = null,
        public ?string $providerReference = null,
        public ?string $error = null
    ) {}
}
