<?php

namespace App\Services\Payment\DTO;

/**
 * Normalized result of a gateway webhook / IPN callback verification.
 */
readonly class WebhookVerificationResult
{
    public function __construct(
        public bool $isVerified = false,
        public ?string $merchantRef = null,
        public ?string $providerRef = null,
        public ?float $amount = null,
        public bool $isPaid = false,
        public ?string $statusString = null,
        public array $rawData = [],
    ) {}
}
