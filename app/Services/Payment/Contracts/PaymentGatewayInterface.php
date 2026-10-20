<?php

namespace App\Services\Payment\Contracts;

use App\Models\LiveSession;
use App\Models\LiveSessionPayment;
use App\Models\User;
use App\Services\Payment\DTO\PaymentInitiationResult;
use App\Services\Payment\DTO\PaymentStatusResult;
use App\Services\Payment\DTO\WebhookVerificationResult;
use Illuminate\Http\Request;

interface PaymentGatewayInterface
{
    /**
     * Create a charge / payment order with the provider.
     *
     * Expected $options keys (driver-dependent): payment_method detail
     * (e.g. 'card' vs 'kiosk' for Fawry), customer_mobile, customer_email,
     * expiry_hours override, etc.
     */
    public function initiatePayment(LiveSession $session, User $patient, array $options = []): PaymentInitiationResult;

    /**
     * Validate an incoming webhook / IPN callback signature and normalize it.
     * Must never throw on tampered payloads — return isVerified=false instead.
     */
    public function verifyWebhook(Request $request): WebhookVerificationResult;

    /**
     * Pull the current payment status from the provider by merchant reference.
     */
    public function queryStatus(string $merchantRef): PaymentStatusResult;

    /**
     * Refund a captured payment. Returns true on success.
     */
    public function refund(LiveSessionPayment $payment): bool;
}
