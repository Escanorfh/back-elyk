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
use App\Services\Payment\FawryPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FawryWebhookController extends Controller
{
    public function __construct(protected FawryPaymentService $fawry) {}

    /**
     * POST /api/v1/webhooks/fawry
     *
     * Public endpoint (no Sanctum auth, no CSRF — api routes are stateless).
     * Authenticity comes solely from the Fawry SHA-256 signature.
     */
    public function handle(Request $request)
    {
        $verification = $this->fawry->verifyWebhook($request);
        $payload = $verification->rawData;

        if (! $verification->isVerified) {
            \Log::warning('Fawry webhook signature mismatch.', [
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

        $payment = LiveSessionPayment::where(
            'merchant_order_reference',
            $verification->merchantRef
        )->first();

        if (! $payment) {
            \Log::warning('Fawry webhook for unknown merchant reference.', [
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
                \Log::warning('Fawry webhook amount below expected fee — held for review.', [
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
                    'fawry_reference_number' => $verification->providerRef,
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
                \Log::warning('Fawry paid notifications failed: '.$e->getMessage(), [
                    'payment_id' => $settled->getKey(),
                ]);
            }

            return response()->json(['status' => 'ok', 'message' => 'Payment verified.']);
        }

        // Verified callback for a non-settled status (FAILED / CANCELLED / ...).
        $payment->fill([
            'status' => 'failed',
            'gateway_status' => $verification->statusString,
            'gateway_response' => $payload,
        ])->save();

        $this->logEvent($payment, 'webhook_received', $payload);
        $this->logEvent($payment, 'failed', ['status' => $verification->statusString]);

        return response()->json(['status' => 'ok', 'message' => 'Payment not settled.']);
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
