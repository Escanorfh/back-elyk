<?php

namespace App\Http\Middleware;

use App\Models\LiveSessionPayment;
use App\Models\PaymentTransactionLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pre-controller hardening for payment webhooks.
 *
 * - Captures the RAW request body (before any parsing) and stashes it on the
 *   request attributes for downstream use.
 * - Enforces an optional provider IP allowlist
 *   (`services.{provider}.webhook_ips`, comma-separated env). Empty = open.
 * - Rejects empty bodies (malformed callbacks).
 * - Writes a pre-parse audit row (raw headers + raw body) to
 *   `payment_transactions_log` when the merchant reference resolves to a
 *   payment; otherwise falls back to the application log.
 *
 * Cryptographic signature verification itself lives in the gateway services
 * (FawryPaymentService / InstaPayService) and runs in the controllers —
 * this middleware covers the network + audit layers. Usage:
 *   ->middleware('webhook.signature:fawry')
 */
class VerifyWebhookSignature
{
    /**
     * Headers never written to the audit log in full.
     *
     * @var array<int, string>
     */
    protected array $redactedHeaders = [
        'authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
    ];

    /**
     * Max raw-body characters stored per audit row.
     */
    protected const MAX_BODY_LOG_LENGTH = 16384;

    public function handle(Request $request, Closure $next, string $provider = ''): Response
    {
        $provider = strtolower(trim($provider)) !== '' ? strtolower(trim($provider)) : 'unknown';

        // Raw body first — untouched by any parsing.
        $rawBody = (string) $request->getContent();
        $request->attributes->set('webhook.raw_body', $rawBody);
        $request->attributes->set('webhook.provider', $provider);

        if (trim($rawBody) === '') {
            \Log::warning("{$provider} webhook with empty body.", ['ip' => $request->ip()]);

            return response()->json(['message' => 'Empty request body.'], 400);
        }

        if (! $this->isIpAllowed($request, $provider)) {
            \Log::warning("{$provider} webhook from non-allowlisted IP.", ['ip' => $request->ip()]);

            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $this->audit($request, $provider, $rawBody);

        return $next($request);
    }

    /**
     * Allowlist check. Unconfigured/empty allowlist = no IP restriction.
     */
    protected function isIpAllowed(Request $request, string $provider): bool
    {
        $allowlist = config("services.{$provider}.webhook_ips", []);

        if (is_string($allowlist)) {
            $allowlist = array_filter(array_map('trim', explode(',', $allowlist)));
        }

        if (! is_array($allowlist) || $allowlist === []) {
            return true;
        }

        return in_array($request->ip(), $allowlist, true);
    }

    /**
     * Pre-parse audit row. Controllers log their own parsed-payload rows;
     * this one preserves the exact bytes + headers as received.
     */
    protected function audit(Request $request, string $provider, string $rawBody): void
    {
        $merchantRef = $this->extractMerchantRef($request, $rawBody);

        $entry = [
            'provider' => $provider,
            'ip' => $request->ip(),
            'headers' => $this->sanitizedHeaders($request),
            'raw_body' => mb_substr($rawBody, 0, self::MAX_BODY_LOG_LENGTH),
            'truncated' => mb_strlen($rawBody) > self::MAX_BODY_LOG_LENGTH,
            'stage' => 'pre-controller capture',
        ];

        if ($merchantRef === null) {
            \Log::info("{$provider} webhook audit (unresolved payment).", [
                'ip' => $request->ip(),
                'headers' => $entry['headers'],
            ]);

            return;
        }

        $payment = LiveSessionPayment::where('merchant_order_reference', $merchantRef)->first();

        if (! $payment) {
            \Log::info("{$provider} webhook audit (unknown merchant reference).", [
                'merchant_ref' => $merchantRef,
                'ip' => $request->ip(),
            ]);

            return;
        }

        try {
            PaymentTransactionLog::create([
                'payment_id' => $payment->id,
                'event_type' => 'webhook_received',
                'payload' => $entry,
            ]);
        } catch (\Throwable $e) {
            \Log::warning("{$provider} webhook audit write failed: ".$e->getMessage());
        }
    }

    /**
     * Best-effort merchant-reference extraction without assuming a
     * content type (JSON, form-encoded, or query string).
     */
    protected function extractMerchantRef(Request $request, string $rawBody): ?string
    {
        $candidates = [
            $request->input('merchantRefNumber'),
            $request->input('merchantRefNum'),
            $request->input('merchant_ref'),
            $request->input('merchant_order_reference'),
            $request->input('ref'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        // Last resort: raw-body regex for the ELY-SESS-..-PAT-..-.. shape.
        if (preg_match('/ELY-SESS-\d+-PAT-\d+-\d+/', $rawBody, $matches)) {
            return $matches[0];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function sanitizedHeaders(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = in_array(strtolower($name), $this->redactedHeaders, true)
                ? '[redacted]'
                : $values;
        }

        return $headers;
    }
}
