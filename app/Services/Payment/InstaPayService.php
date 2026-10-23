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
 * InstaPay (Egypt Instant Payment Network) driver.
 *
 * Two operating modes (see `config/services.php → instapay.mode`):
 *
 * 1. Aggregator mode ('aggregator'): charge requests go through an
 *    EBC-certified aggregator's InstaPay rail over HTTPS, and settlement
 *    notifications arrive as server webhooks. Adapt the endpoint paths /
 *    field names below to the aggregator you contract with.
 *
 * 2. Direct IPA mode ('direct', default): no external API call. We build a
 *    unique transaction reference, an `instapay://pay` deep link and an
 *    EMVCo-framed QR payload string around the clinic IPA. The patient pays
 *    from any InstaPay app, optionally uploads a receipt, and the payment is
 *    reconciled by reference match (+ admin approval in a later phase).
 */
class InstaPayService implements PaymentGatewayInterface
{
    public const MODE_AGGREGATOR = 'aggregator';

    public const MODE_DIRECT = 'direct';

    public const DEFAULT_EXPIRY_HOURS = 24;

    protected string $mode;

    protected string $clinicIpa;

    /**
     * Static doctor payment link (e.g. https://ipn.eg/S/...). When set, it is
     * used as-is instead of the generated instapay:// deep link.
     */
    protected string $paymentLink;

    protected string $aggregatorBaseUrl;

    protected string $aggregatorApiKey;

    protected string $webhookSecret;

    protected string $returnUrl;

    public function __construct()
    {
        $config = config('services.instapay', []);

        $this->mode = strtolower((string) ($config['mode'] ?? self::MODE_DIRECT));
        $this->clinicIpa = (string) ($config['clinic_ipa'] ?? '');
        $this->paymentLink = (string) ($config['payment_link'] ?? '');
        $this->aggregatorBaseUrl = rtrim((string) ($config['aggregator_base_url'] ?? ''), '/');
        $this->aggregatorApiKey = (string) ($config['aggregator_api_key'] ?? '');
        $this->webhookSecret = (string) ($config['webhook_secret'] ?? '');
        $this->returnUrl = (string) ($config['return_url'] ?? '');
    }

    // ------------------------------------------------------------------
    // PaymentGatewayInterface
    // ------------------------------------------------------------------

    /**
     * Build an InstaPay payment request.
     *
     * Supported $options:
     * - merchant_ref, amount (→ $session->fee_amount), currency (→ fee_currency ?? 'EGP')
     * - mode: 'aggregator' | 'direct' (overrides configured mode per call)
     * - expiry_hours, note, customer_mobile
     */
    public function initiatePayment(LiveSession $session, User $patient, array $options = []): PaymentInitiationResult
    {
        $amount = $options['amount'] ?? $session->fee_amount;

        if ($amount === null || ! is_numeric($amount) || (float) $amount <= 0) {
            throw new InvalidArgumentException('InstaPay payment amount is missing or invalid.');
        }

        $formatted = self::formatAmount($amount);
        $currency = (string) ($options['currency'] ?? $session->fee_currency ?? 'EGP');
        $merchantRef = (string) ($options['merchant_ref'] ?? self::generateMerchantRef($session, $patient));
        $expiryHours = (int) ($options['expiry_hours'] ?? self::DEFAULT_EXPIRY_HOURS);
        $mode = strtolower((string) ($options['mode'] ?? $this->mode));

        if ($mode === self::MODE_AGGREGATOR) {
            return $this->initiateViaAggregator($merchantRef, $formatted, $currency, $patient, $options, $expiryHours);
        }

        return $this->initiateDirect($merchantRef, $formatted, $currency, $options, $expiryHours);
    }

    /**
     * Validate an InstaPay IPN / aggregator webhook via HMAC-SHA256.
     * Never throws — failures yield isVerified=false.
     *
     * Convention: HMAC-SHA256 hex of the RAW request body, keyed with
     * `services.instapay.webhook_secret`, delivered in the
     * `X-Instapay-Signature` header (fallbacks: `X-Signature`, body `signature`).
     */
    public function verifyWebhook(Request $request): WebhookVerificationResult
    {
        try {
            $data = $request->all();
            $rawBody = $request->getContent() ?: json_encode($data);

            $provided = $request->header('X-Instapay-Signature')
                ?? $request->header('X-Signature')
                ?? (string) ($data['signature'] ?? $data['hmac'] ?? '');

            if ($provided === '' || $this->webhookSecret === '') {
                return new WebhookVerificationResult(rawData: $data);
            }

            $expected = hash_hmac('sha256', (string) $rawBody, $this->webhookSecret);
            $verified = hash_equals($expected, strtolower(trim($provided)));

            $merchantRef = self::stringOrNull(
                $data['merchant_ref'] ?? $data['merchantRefNumber'] ?? $data['merchant_order_reference'] ?? null
            );
            $providerRef = self::stringOrNull(
                $data['provider_ref'] ?? $data['transaction_id'] ?? $data['rrn'] ?? $data['instapay_reference'] ?? null
            );
            $status = self::stringOrNull($data['status'] ?? $data['orderStatus'] ?? null);
            $amountValue = $data['amount'] ?? $data['orderAmount'] ?? null;

            $isPaid = $verified && in_array(strtolower((string) $status), ['paid', 'success', 'settled', 'completed', 'approved'], true);

            return new WebhookVerificationResult(
                isVerified: $verified,
                merchantRef: $merchantRef,
                providerRef: $providerRef,
                amount: is_numeric($amountValue) ? (float) $amountValue : null,
                isPaid: $isPaid,
                statusString: $status,
                rawData: $data,
            );
        } catch (Throwable) {
            return new WebhookVerificationResult(rawData: $request->all());
        }
    }

    /**
     * Aggregator mode: remote status lookup. Direct mode: local reconciliation
     * state (no provider to query — bank transfer settles off-platform).
     * Never throws.
     */
    public function queryStatus(string $merchantRef): PaymentStatusResult
    {
        try {
            if ($this->mode === self::MODE_AGGREGATOR) {
                return $this->queryAggregatorStatus($merchantRef);
            }

            $payment = LiveSessionPayment::where('merchant_order_reference', $merchantRef)->first();

            if (! $payment) {
                return new PaymentStatusResult(merchantRef: $merchantRef, status: 'unknown');
            }

            $status = (string) $payment->status;

            return new PaymentStatusResult(
                merchantRef: $merchantRef,
                status: $status,
                isPaid: $status === 'paid',
                providerRef: $payment->instapay_reference_number,
                amount: is_numeric($payment->amount) ? (float) $payment->amount : null,
                gatewayStatus: $payment->gateway_status,
                rawData: ['mode' => self::MODE_DIRECT],
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
     * Aggregator mode: remote refund call. Direct mode: bank transfers cannot
     * be auto-refunded — returns false (manual refund + admin flow instead).
     */
    public function refund(LiveSessionPayment $payment): bool
    {
        try {
            if ($this->mode !== self::MODE_AGGREGATOR) {
                return false;
            }

            $this->ensureAggregatorConfigured();

            $merchantRef = (string) ($payment->merchant_order_reference ?? '');

            if ($merchantRef === '') {
                return false;
            }

            $response = Http::timeout(20)
                ->acceptJson()
                ->withToken($this->aggregatorApiKey)
                ->post($this->aggregatorBaseUrl.'/instapay/payment-requests/'.$merchantRef.'/refund', [
                    'amount' => self::formatAmount($payment->amount ?? 0),
                    'reason' => 'Session cancelled — auto refund',
                ]);

            if (! $response->successful()) {
                return false;
            }

            $data = $response->json() ?? [];
            $status = strtolower((string) ($data['status'] ?? ''));

            return in_array($status, ['refunded', 'success', 'approved'], true);
        } catch (Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Mode implementations
    // ------------------------------------------------------------------

    protected function initiateViaAggregator(
        string $merchantRef,
        string $amount,
        string $currency,
        User $patient,
        array $options,
        int $expiryHours,
    ): PaymentInitiationResult {
        $this->ensureAggregatorConfigured();

        // Internal convention — align field names with your aggregator's docs.
        $signature = hash_hmac(
            'sha256',
            implode('|', [$merchantRef, $amount, $currency]),
            $this->aggregatorApiKey
        );

        $payload = [
            'merchant_ref' => $merchantRef,
            'amount' => $amount,
            'currency' => $currency,
            'customer_name' => $patient->name,
            'customer_email' => $options['customer_email'] ?? $patient->email,
            'customer_mobile' => $options['customer_mobile'] ?? null,
            'note' => (string) ($options['note'] ?? $merchantRef),
            'return_url' => $this->returnUrl,
            'expiry_hours' => $expiryHours,
            'signature' => $signature,
        ];

        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->withToken($this->aggregatorApiKey)
                ->post($this->aggregatorBaseUrl.'/instapay/payment-requests', $payload);
        } catch (Throwable $e) {
            throw new RuntimeException('InstaPay aggregator request failed: '.$e->getMessage(), 0, $e);
        }

        $data = $response->json() ?? [];

        if (! $response->successful()) {
            throw new RuntimeException(
                'InstaPay aggregator request rejected: '.($data['message'] ?? $response->body())
            );
        }

        return new PaymentInitiationResult(
            referenceNumber: self::stringOrNull(
                $data['provider_ref'] ?? $data['transaction_id'] ?? $data['reference_number'] ?? $merchantRef
            ),
            checkoutUrl: self::stringOrNull($data['checkout_url'] ?? $data['payment_url'] ?? null),
            deepLink: self::stringOrNull($data['deep_link'] ?? $data['deeplink'] ?? null),
            expiryTime: now()->addHours($expiryHours),
            rawData: $data,
        );
    }

    protected function initiateDirect(
        string $merchantRef,
        string $amount,
        string $currency,
        array $options,
        int $expiryHours,
    ): PaymentInitiationResult {
        // Static doctor link wins when configured (or passed per call) —
        // the patient opens it, pays, and keeps $merchantRef in the remarks
        // so the admin can reconcile and approve.
        $staticLink = (string) ($options['payment_link'] ?? $this->paymentLink);

        if ($staticLink !== '') {
            return new PaymentInitiationResult(
                referenceNumber: $merchantRef,
                checkoutUrl: null,
                deepLink: $staticLink,
                expiryTime: now()->addHours($expiryHours),
                rawData: [
                    'mode' => self::MODE_DIRECT,
                    'payment_link' => $staticLink,
                    'amount' => $amount,
                    'currency' => $currency,
                    'merchant_ref' => $merchantRef,
                    'qr_string' => $staticLink,
                    'instruction' => 'Open the doctor payment link, transfer the exact amount, and keep the reference code in the transfer remarks.',
                ],
            );
        }

        if ($this->clinicIpa === '') {
            throw new RuntimeException(
                'InstaPay is not configured. Set INSTAPAY_PAYMENT_LINK or INSTAPAY_CLINIC_IPA.'
            );
        }

        $note = (string) ($options['note'] ?? $merchantRef);
        $deepLink = self::generateDeepLink($this->clinicIpa, $amount, $currency, $merchantRef, $note);
        $qrString = self::generateEmvQrString($this->clinicIpa, $amount, $merchantRef);

        return new PaymentInitiationResult(
            referenceNumber: $merchantRef,
            checkoutUrl: null,
            deepLink: $deepLink,
            expiryTime: now()->addHours($expiryHours),
            rawData: [
                'mode' => self::MODE_DIRECT,
                'clinic_ipa' => $this->clinicIpa,
                'amount' => $amount,
                'currency' => $currency,
                'merchant_ref' => $merchantRef,
                'qr_string' => $qrString,
                'instruction' => 'Pay the exact amount from any InstaPay app and keep the reference in the transfer note. Upload the receipt for reconciliation.',
            ],
        );
    }

    protected function queryAggregatorStatus(string $merchantRef): PaymentStatusResult
    {
        $this->ensureAggregatorConfigured();

        $response = Http::timeout(20)
            ->acceptJson()
            ->withToken($this->aggregatorApiKey)
            ->get($this->aggregatorBaseUrl.'/instapay/payment-requests/'.$merchantRef);

        $data = $response->json() ?? [];
        $gatewayStatus = self::stringOrNull($data['status'] ?? null);
        $normalized = strtolower((string) $gatewayStatus);

        $status = match ($normalized) {
            'paid', 'success', 'settled', 'completed', 'approved' => 'paid',
            'refunded' => 'refunded',
            'pending', 'created', 'processing' => 'pending',
            '' => 'unknown',
            default => 'failed',
        };

        $amountValue = $data['amount'] ?? null;

        return new PaymentStatusResult(
            merchantRef: $merchantRef,
            status: $status,
            isPaid: $status === 'paid',
            providerRef: self::stringOrNull($data['provider_ref'] ?? $data['transaction_id'] ?? null),
            amount: is_numeric($amountValue) ? (float) $amountValue : null,
            gatewayStatus: $gatewayStatus,
            rawData: $data,
        );
    }

    // ------------------------------------------------------------------
    // Static builders (pure — unit-testable without credentials)
    // ------------------------------------------------------------------

    /**
     * Canonical merchant order reference, e.g. ELY-SESS-102-PAT-45-1696200000.
     * Patients copy this into the InstaPay transfer note for reconciliation.
     */
    public static function generateMerchantRef(LiveSession $session, User $patient): string
    {
        return sprintf('ELY-SESS-%d-PAT-%d-%d', $session->getKey(), $patient->getKey(), time());
    }

    /**
     * InstaPay app deep link carrying payee IPA, exact amount and reference.
     */
    public static function generateDeepLink(
        string $clinicIpa,
        string $amount,
        string $currency,
        string $merchantRef,
        string $note = '',
    ): string {
        return 'instapay://pay?'.http_build_query([
            'ipa' => $clinicIpa,
            'amount' => $amount,
            'currency' => $currency,
            'ref' => $merchantRef,
            'note' => $note !== '' ? $note : $merchantRef,
        ]);
    }

    /**
     * Best-effort EMVCo-framed QR payload string (TLV fields + CRC16-CCITT).
     * Template layout is an app convention — confirm scannability with the
     * clinic's bank / InstaPay app before printing it on invoices.
     *
     * Layout: 00 format, 01 dynamic, 26 merchant account (00 GUI, 01 IPA,
     * 02 ref), 52 MCC 6012, 53 currency 818 (EGP), 54 amount, 58 EG,
     * 59 merchant name, 62 additional data (07 ref), 63 CRC.
     */
    public static function generateEmvQrString(string $clinicIpa, string $amount, string $merchantRef): string
    {
        $merchantAccount = self::tlv('00', 'instapay')
            .self::tlv('01', $clinicIpa)
            .self::tlv('02', $merchantRef);

        $payload = self::tlv('00', '01')
            .self::tlv('01', '12')
            .self::tlv('26', $merchantAccount)
            .self::tlv('52', '6012')
            .self::tlv('53', '818')
            .self::tlv('54', $amount)
            .self::tlv('58', 'EG')
            .self::tlv('59', 'ELYECLINIC')
            .self::tlv('62', self::tlv('07', $merchantRef))
            .'6304';

        return $payload.self::crc16($payload.'6304');
    }

    /**
     * EMVCo TLV field: ID (2 chars) + length (2 chars) + value.
     */
    public static function tlv(string $id, string $value): string
    {
        return $id.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }

    /**
     * CRC16-CCITT (poly 0x1021, init 0xFFFF) hex, per EMVCo spec.
     */
    public static function crc16(string $input): string
    {
        $crc = 0xFFFF;

        for ($i = 0, $len = strlen($input); $i < $len; $i++) {
            $crc ^= ord($input[$i]) << 8;

            for ($j = 0; $j < 8; $j++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    protected function ensureAggregatorConfigured(): void
    {
        if ($this->aggregatorBaseUrl === '' || $this->aggregatorApiKey === '') {
            throw new RuntimeException(
                'InstaPay aggregator is not configured. Set INSTAPAY_AGGREGATOR_BASE_URL and INSTAPAY_AGGREGATOR_API_KEY.'
            );
        }
    }

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
}
