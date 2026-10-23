<?php

namespace App\Services\Payment;

use App\Models\LiveSession;
use App\Models\LiveSessionPayment;
use App\Models\User;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\DTO\PaymentInitiationResult;
use App\Services\Payment\DTO\PaymentStatusResult;
use App\Services\Payment\DTO\WebhookVerificationResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * FawryPay ECommerce API v2 driver.
 *
 * Field names follow the FawryPay ECommerce documentation — verify request /
 * response shapes against the Fawry sandbox before going live.
 */
class FawryPaymentService implements PaymentGatewayInterface
{
    public const METHOD_KIOSK = 'PAYATFAWRY';

    public const METHOD_CARD = 'CARD';

    public const DEFAULT_EXPIRY_HOURS = 24;

    protected string $merchantCode;

    protected string $securityKey;

    protected string $baseUrl;

    protected string $returnUrl;

    public function __construct()
    {
        $config = config('services.fawry', []);

        $this->merchantCode = (string) ($config['merchant_code'] ?? '');
        $this->securityKey = (string) ($config['security_key'] ?? '');
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? 'https://atfawry.fawrystaging.com'), '/');
        $this->returnUrl = (string) ($config['return_url'] ?? '');
    }

    // ------------------------------------------------------------------
    // PaymentGatewayInterface
    // ------------------------------------------------------------------

    /**
     * Create a Fawry charge.
     *
     * Supported $options:
     * - merchant_ref: string (default: generated ELY-SESS-..-PAT-..-.. ref)
     * - amount: numeric (default: $session->fee_amount)
     * - currency: string (default: $session->fee_currency ?? 'EGP')
     * - method: 'kiosk' (PAYATFAWRY, default) | 'card' (CARD hosted checkout)
     * - item_id, description, expiry_hours, customer_mobile, customer_email
     *
     * @throws InvalidArgumentException when amount cannot be resolved.
     * @throws RuntimeException when credentials are missing or Fawry errors.
     */
    public function initiatePayment(LiveSession $session, User $patient, array $options = []): PaymentInitiationResult
    {
        $this->ensureConfigured();

        $amount = $options['amount'] ?? $session->fee_amount;

        if ($amount === null || ! is_numeric($amount) || (float) $amount <= 0) {
            throw new InvalidArgumentException('Fawry payment amount is missing or invalid.');
        }

        $price = self::formatAmount($amount);
        $currency = (string) ($options['currency'] ?? $session->fee_currency ?? 'EGP');
        $merchantRef = (string) ($options['merchant_ref'] ?? self::generateMerchantRef($session, $patient));
        $customerProfileId = (string) $patient->getKey();
        $itemId = (string) ($options['item_id'] ?? 'SESS-'.$session->getKey());
        $quantity = (int) ($options['quantity'] ?? 1);
        $expiryHours = (int) ($options['expiry_hours'] ?? self::DEFAULT_EXPIRY_HOURS);
        $paymentMethod = strtolower((string) ($options['method'] ?? 'kiosk')) === 'card'
            ? self::METHOD_CARD
            : self::METHOD_KIOSK;

        $signature = self::generateChargeSignature(
            $this->merchantCode,
            $merchantRef,
            $customerProfileId,
            $this->returnUrl,
            $itemId,
            $quantity,
            $price,
            $expiryHours,
            $this->securityKey,
        );

        $payload = [
            'merchantCode' => $this->merchantCode,
            'merchantRefNum' => $merchantRef,
            'customerProfileId' => $customerProfileId,
            'customerName' => $patient->name,
            'customerMobile' => $options['customer_mobile'] ?? null,
            'customerEmail' => $options['customer_email'] ?? $patient->email,
            'paymentMethod' => $paymentMethod,
            'amount' => $price,
            'currencyCode' => $currency,
            'description' => (string) ($options['description'] ?? "Session #{$session->getKey()} payment"),
            'chargeItems' => [
                [
                    'itemId' => $itemId,
                    'description' => (string) ($options['description'] ?? "Session #{$session->getKey()}"),
                    'price' => $price,
                    'quantity' => $quantity,
                ],
            ],
            'returnUrl' => $this->returnUrl,
            'expiryHours' => $expiryHours,
            'signature' => $signature,
        ];

        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->post($this->baseUrl.'/ECommerceWeb/Fawry/payments/charge', $payload);
        } catch (Throwable $e) {
            throw new RuntimeException('Fawry charge request failed: '.$e->getMessage(), 0, $e);
        }

        $data = $response->json() ?? [];

        if (! $response->successful()) {
            throw new RuntimeException(
                'Fawry charge request rejected: '.($data['statusDescription'] ?? $response->body())
            );
        }

        return new PaymentInitiationResult(
            referenceNumber: self::stringOrNull($data['referenceNumber'] ?? $data['fawryRefNumber'] ?? null),
            checkoutUrl: self::stringOrNull(
                $data['paymentUrl'] ?? $data['redirectUrl'] ?? $data['nextAction']['redirectUrl'] ?? null
            ),
            deepLink: null,
            expiryTime: now()->addHours($expiryHours),
            rawData: $data,
        );
    }

    /**
     * Validate a Fawry server callback. Never throws — tampered payloads
     * yield isVerified=false.
     */
    public function verifyWebhook(Request $request): WebhookVerificationResult
    {
        try {
            $data = $request->all();

            $merchantRef = self::stringOrNull($data['merchantRefNumber'] ?? $data['merchantRefNum'] ?? null);
            $fawryRef = self::stringOrNull($data['fawryRefNumber'] ?? $data['fawryRefNo'] ?? null);
            $amount = self::stringOrNull($data['orderAmount'] ?? $data['orderPrice'] ?? $data['amount'] ?? null);
            $paidAmount = self::stringOrNull($data['paidAmount'] ?? $amount);
            $status = self::stringOrNull($data['orderStatus'] ?? $data['paymentStatus'] ?? null);
            $method = self::stringOrNull($data['paymentMethod'] ?? null);
            $signature = (string) ($data['signature'] ?? '');

            if ($merchantRef === null || $fawryRef === null || $signature === '' || $this->securityKey === '') {
                return new WebhookVerificationResult(rawData: $data);
            }

            $expected = self::generateWebhookSignature(
                $this->merchantCode,
                $merchantRef,
                $fawryRef,
                self::formatAmount($amount ?? 0),
                self::formatAmount($paidAmount ?? 0),
                (string) $status,
                (string) $method,
                $this->securityKey,
            );

            $verified = hash_equals($expected, $signature);
            $isPaid = $verified && strtoupper((string) $status) === 'PAID';

            return new WebhookVerificationResult(
                isVerified: $verified,
                merchantRef: $merchantRef,
                providerRef: $fawryRef,
                amount: is_numeric($paidAmount) ? (float) $paidAmount : null,
                isPaid: $isPaid,
                statusString: $status,
                rawData: $data,
            );
        } catch (Throwable) {
            return new WebhookVerificationResult(rawData: $request->all());
        }
    }

    /**
     * Query charge status via GET /ECommerceWeb/Fawry/payments/status.
     * Transport failures yield status 'unknown' (never throws).
     */
    public function queryStatus(string $merchantRef): PaymentStatusResult
    {
        try {
            $this->ensureConfigured();

            $signature = hash('sha256', $this->merchantCode.$merchantRef.$this->securityKey);

            $response = Http::timeout(20)
                ->acceptJson()
                ->get($this->baseUrl.'/ECommerceWeb/Fawry/payments/status', [
                    'merchantCode' => $this->merchantCode,
                    'merchantRefNumber' => $merchantRef,
                    'signature' => $signature,
                ]);

            $data = $response->json() ?? [];
            $gatewayStatus = self::stringOrNull($data['orderStatus'] ?? $data['paymentStatus'] ?? null);

            return new PaymentStatusResult(
                merchantRef: $merchantRef,
                status: self::normalizeStatus($gatewayStatus),
                isPaid: strtoupper((string) $gatewayStatus) === 'PAID',
                providerRef: self::stringOrNull($data['fawryRefNumber'] ?? $data['fawryRefNo'] ?? null),
                amount: isset($data['orderAmount']) && is_numeric($data['orderAmount'])
                    ? (float) $data['orderAmount']
                    : null,
                gatewayStatus: $gatewayStatus,
                rawData: $data,
            );
        } catch (Throwable $e) {
            return new PaymentStatusResult(
                merchantRef: $merchantRef,
                status: 'unknown',
                rawData: ['error' => $e->getMessage()],
            );
        }
    }

    /**
     * Refund a captured Fawry charge. Returns false on any failure.
     */
    public function refund(LiveSessionPayment $payment): bool
    {
        try {
            $this->ensureConfigured();

            $merchantRef = (string) ($payment->merchant_order_reference ?? '');
            $fawryRef = (string) ($payment->fawry_reference_number ?? '');
            $amount = self::formatAmount($payment->amount ?? 0);

            if ($merchantRef === '' || $fawryRef === '') {
                return false;
            }

            // Refund request signature — verify exact field list against the
            // Fawry sandbox docs if refunds are rejected.
            $signature = hash('sha256', $this->merchantCode.$merchantRef.$fawryRef.$amount.$this->securityKey);

            $response = Http::timeout(20)
                ->acceptJson()
                ->post($this->baseUrl.'/ECommerceWeb/Fawry/payments/refund', [
                    'merchantCode' => $this->merchantCode,
                    'merchantRefNumber' => $merchantRef,
                    'fawryRefNumber' => $fawryRef,
                    'refundAmount' => $amount,
                    'reason' => 'Session cancelled — auto refund',
                    'signature' => $signature,
                ]);

            if (! $response->successful()) {
                return false;
            }

            $data = $response->json() ?? [];
            $status = strtoupper((string) ($data['orderStatus'] ?? $data['status'] ?? ''));

            return in_array($status, ['REFUNDED', 'SUCCESS', 'PAID'], true)
                || ($data['statusCode'] ?? null) === 200;
        } catch (Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Signature helpers (pure — unit-testable without credentials)
    // ------------------------------------------------------------------

    /**
     * Charge signature:
     * sha256(merchantCode + merchantRefNum + customerProfileId + returnUrl
     *   + itemId + quantity + price + expiryHours + securityKey)
     */
    public static function generateChargeSignature(
        string $merchantCode,
        string $merchantRef,
        string $customerProfileId,
        string $returnUrl,
        string $itemId,
        int $quantity,
        string $price,
        int $expiryHours,
        string $securityKey,
    ): string {
        return hash('sha256', implode('', [
            $merchantCode,
            $merchantRef,
            $customerProfileId,
            $returnUrl,
            $itemId,
            $quantity,
            $price,
            $expiryHours,
            $securityKey,
        ]));
    }

    /**
     * Webhook / server-callback signature:
     * sha256(merchantCode + merchantRefNum + fawryRefNumber + amount
     *   + paidAmount + paymentStatus + paymentMethod + securityKey)
     */
    public static function generateWebhookSignature(
        string $merchantCode,
        string $merchantRef,
        string $fawryRef,
        string $amount,
        string $paidAmount,
        string $paymentStatus,
        string $paymentMethod,
        string $securityKey,
    ): string {
        return hash('sha256', implode('', [
            $merchantCode,
            $merchantRef,
            $fawryRef,
            $amount,
            $paidAmount,
            $paymentStatus,
            $paymentMethod,
            $securityKey,
        ]));
    }

    /**
     * Canonical merchant order reference, e.g. ELY-SESS-102-PAT-45-1696200000.
     */
    public static function generateMerchantRef(LiveSession $session, User $patient): string
    {
        return sprintf(
            'ELY-SESS-%d-PAT-%d-%d',
            $session->getKey(),
            $patient->getKey(),
            time()
        );
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    protected function ensureConfigured(): void
    {
        if ($this->merchantCode === '' || $this->securityKey === '') {
            throw new RuntimeException(
                'Fawry is not configured. Set FAWRY_MERCHANT_CODE and FAWRY_SECURITY_KEY.'
            );
        }
    }

    /**
     * Fawry expects amounts with exactly two decimals ("500.00").
     */
    public static function formatAmount(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    protected static function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return null;
        }

        return (string) $value;
    }

    /**
     * Normalize a Fawry orderStatus to our local payment vocabulary.
     */
    protected static function normalizeStatus(?string $gatewayStatus): string
    {
        return match (strtoupper((string) $gatewayStatus)) {
            'PAID' => 'paid',
            'REFUNDED' => 'refunded',
            'NEW' => 'pending',
            '' => 'unknown',
            default => 'failed',
        };
    }
}
