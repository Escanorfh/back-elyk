<?php

namespace App\Http\Controllers\Webhooks;

use App\Events\AdminApprovalQueueUpdatedEvent;
use App\Events\LiveSessionPaymentVerified;
use App\Events\PaymentStatusUpdatedEvent;
use App\Notifications\NewPaymentNeedsApprovalNotification;
use App\Notifications\PaymentVerifiedNotification;
use App\Http\Controllers\Controller;
use App\Models\LiveSessionPayment;
use App\Models\PaymentTransactionLog;
use App\Services\Payment\InstaPayService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InstaPayWebhookController extends Controller
{
    /**
     * Maximum IPN age in seconds (replay-attack window).
     */
    public const TIMESTAMP_TOLERANCE_SECONDS = 300;

    public function __construct(protected InstaPayService $instapay) {}

    /**
     * POST /api/v1/webhooks/instapay
     *
     * Public endpoint (no Sanctum auth, no CSRF — api routes are stateless).
     * Authenticity comes from the HMAC-SHA256 signature plus the
     * timestamp freshness window.
     */
    public function handle(Request $request)
    {
        $verification = $this->instapay->verifyWebhook($request);
        $payload = $verification->rawData;

        if (! $verification->isVerified) {
            \Log::warning('InstaPay IPN signature mismatch.', [
                'merchant_ref' => $verification->merchantRef,
                'ip' => $request->ip(),
            ]);

            if ($verification->merchantRef) {
                $this->logForRef($verification->merchantRef, 'webhook_received', [
                    'verified' => false,
                    'payload' => $payload,
                ]);
            }

            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        if (! $this->isTimestampFresh($payload)) {
            \Log::warning('InstaPay IPN outside replay window.', [
                'merchant_ref' => $verification->merchantRef,
                'ip' => $request->ip(),
            ]);

            if ($verification->merchantRef) {
                $this->logForRef($verification->merchantRef, 'webhook_received', [
                    'verified' => true,
                    'rejected' => 'stale_timestamp',
                    'payload' => $payload,
                ]);
            }

            return response()->json(['message' => 'Stale notification.'], 400);
        }

        $payment = LiveSessionPayment::where(
            'merchant_order_reference',
            $verification->merchantRef
        )->first();

        if (! $payment) {
            \Log::warning('InstaPay IPN for unknown merchant reference.', [
                'merchant_ref' => $verification->merchantRef,
            ]);

            return response()->json(['message' => 'Unknown merchant reference.'], 404);
        }

        // Idempotency: already settled — acknowledge without reprocessing.
        if ($payment->status === 'paid') {
            $this->logEvent($payment, 'webhook_received', [
                'duplicate' => true,
                'payload' => $payload,
            ]);

            return response()->json(['status' => 'ok', 'message' => 'Already processed.']);
        }

        if ($verification->isPaid) {
            // Amount sanity: never credit a payment for less than the fee.
            if ($verification->amount !== null && $verification->amount < (float) $payment->amount) {
                \Log::warning('InstaPay IPN amount below expected fee — held for review.', [
                    'payment_id' => $payment->id,
                    'expected' => $payment->amount,
                    'received' => $verification->amount,
                ]);

                $payment->fill([
                    'gateway_status' => $verification->statusString,
                    'gateway_response' => $payload,
                ])->save();

                $this->logEvent($payment, 'webhook_received', ['held' => true, 'payload' => $payload]);

                return response()->json(['status' => 'ok', 'message' => 'Held for review.']);
            }

            DB::transaction(function () use ($payment, $verification, $payload) {
                $payment->fill([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'instapay_reference_number' => $verification->providerRef,
                    'gateway_status' => $verification->statusString,
                    'gateway_response' => $payload,
                ])->save();

                $this->logEvent($payment, 'webhook_received', $payload);
                $this->logEvent($payment, 'verified', [
                    'provider_ref' => $verification->providerRef,
                    'amount' => $verification->amount,
                ]);
            });

            LiveSessionPaymentVerified::dispatch($payment->fresh());

            $settled = $payment->fresh();
            PaymentStatusUpdatedEvent::dispatch($settled);
            AdminApprovalQueueUpdatedEvent::dispatch(
                AdminApprovalQueueUpdatedEvent::pendingCount(),
                $settled->live_session_id,
                'paid'
            );

            try {
                $settled->patient?->notify(new PaymentVerifiedNotification($settled));
                NewPaymentNeedsApprovalNotification::notifyAdmins($settled);
            } catch (\Throwable $e) {
                \Log::warning('InstaPay paid notifications failed: '.$e->getMessage(), [
                    'payment_id' => $settled->getKey(),
                ]);
            }

            return response()->json(['status' => 'ok', 'message' => 'Payment verified.']);
        }

        // Verified callback for a non-settled status.
        $payment->fill([
            'status' => 'failed',
            'gateway_status' => $verification->statusString,
            'gateway_response' => $payload,
        ])->save();

        $this->logEvent($payment, 'webhook_received', $payload);
        $this->logEvent($payment, 'failed', ['status' => $verification->statusString]);

        return response()->json(['status' => 'ok', 'message' => 'Payment not settled.']);
    }

    /**
     * Reject notifications older/newer than the tolerance window.
     * Accepts epoch seconds, epoch millis, or ISO-8601 strings across common
     * field names. Payloads without any timestamp are accepted (with a log
     * note) so aggregators that don't send one keep working.
     */
    protected function isTimestampFresh(array $payload): bool
    {
        $raw = $payload['timestamp'] ?? $payload['sent_at'] ?? $payload['created_at']
            ?? $payload['event_time'] ?? $payload['notified_at'] ?? null;

        if ($raw === null || $raw === '') {
            \Log::notice('InstaPay IPN without timestamp — freshness check skipped.', [
                'merchant_ref' => $payload['merchant_ref'] ?? $payload['merchantRefNumber'] ?? null,
            ]);

            return true;
        }

        try {
            if (is_numeric($raw)) {
                $value = (float) $raw;
                // Epoch millis → seconds.
                $sentAt = Carbon::createFromTimestamp((int) ($value > 1e12 ? $value / 1000 : $value));
            } else {
                $sentAt = Carbon::parse((string) $raw);
            }
        } catch (\Throwable) {
            return false;
        }

        return abs(now()->diffInSeconds($sentAt, true)) <= self::TIMESTAMP_TOLERANCE_SECONDS;
    }

    protected function logEvent(LiveSessionPayment $payment, string $event, mixed $payload): void
    {
        PaymentTransactionLog::create([
            'payment_id' => $payment->id,
            'event_type' => $event,
            'payload' => is_array($payload) ? $payload : ['data' => $payload],
        ]);
    }

    protected function logForRef(string $merchantRef, string $event, mixed $payload): void
    {
        $payment = LiveSessionPayment::where('merchant_order_reference', $merchantRef)->first();

        if ($payment) {
            $this->logEvent($payment, $event, $payload);
        }
    }
}
