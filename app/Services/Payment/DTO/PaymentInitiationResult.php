<?php

namespace App\Services\Payment\DTO;

/**
 * Normalized result of a gateway charge / order creation.
 */
readonly class PaymentInitiationResult
{
    public function __construct(
        public ?string $referenceNumber = null,
        public ?string $checkoutUrl = null,
        public ?string $deepLink = null,
        public ?\DateTimeInterface $expiryTime = null,
        public array $rawData = [],
    ) {}
}
