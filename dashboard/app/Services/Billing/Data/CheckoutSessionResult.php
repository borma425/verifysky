<?php

namespace App\Services\Billing\Data;

readonly class CheckoutSessionResult
{
    public function __construct(
        public bool $ok,
        public ?string $redirectUrl = null,
        public ?string $providerReference = null,
        public ?string $error = null
    ) {}
}
