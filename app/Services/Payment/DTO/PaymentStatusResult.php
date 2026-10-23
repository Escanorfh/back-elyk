<?php

namespace App\Services\Payment\DTO;

/**
 * Normalized result of a gateway status query by merchant reference.
 */
readonly class PaymentStatusResult
{
    public function __construct(
        public string $merchantRef = '',
        public string $status = 'pending',
        public bool $isPaid = false,
        public ?string $providerRef = null,
        public ?float $amount = null,
        public ?string $gatewayStatus = null,
        public array $rawData = [],
    ) {}
}
