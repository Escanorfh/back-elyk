<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\LiveSession;
use App\Models\LiveSessionPayment;
use App\Models\LiveSessionPatient;
use App\Models\PaymentTransactionLog;
use App\Services\Payment\PaymentGatewayManager;
use Illuminate\Http\Request;
use Throwable;

class PaymentController extends Controller
{
    public function __construct(protected PaymentGatewayManager $gateways) {}

    /**
     * POST /api/v1/live-sessions/{session}/pay/initiate
     *
     * The price is ALWAYS resolved server-side (session fee → system default).
     * Any amount sent by the client is ignored.
     */
    public function initiate(Request $request, LiveSession $session)
    {
        $user = $request->user();
        if ($user->role !== 'patient') {
            abort(403);
        }

        if (! in_array($session->status, ['scheduled', 'live'])) {
            abort(400, 'Payment is available only after the live is scheduled.');
        }

        if (! $this->canAccess($session, $user)) {
            abort(403);
        }

        $validated = $request->validate([
            'payment_method' => 'required|in:fawry,instapay',
            'channel' => 'nullable|in:kiosk,card',
            'customer_mobile' => 'nullable|string|max:20',
        ]);

        $method = $validated['payment_method'];
        $amount = $session->fee_amount ?? AppSetting::getValue('default_session_price');
        $currency = $session->fee_currency
            ?? AppSetting::getValue('session_currency', 'EGP');

        if ($amount === null || (int) $amount <= 0) {
            abort(400, 'This session has no fee.');
        }

        $payment = LiveSessionPayment::firstOrNew([
            'live_session_id' => $session->id,
            'patient_id' => $user->id,
        ]);

        if ($payment->exists && $payment->status === 'paid') {
            return response()->json(['message' => 'Already paid', 'data' => $payment]);
        }

        $merchantRef = $method === 'fawry'
            ? \App\Services\Payment\FawryPaymentService::generateMerchantRef($session, $user)
            : \App\Services\Payment\InstaPayService::generateMerchantRef($session, $user);

        $expiryHours = (int) AppSetting::getValue(
            'fawry_expiry_hours',
            \App\Services\Payment\FawryPaymentService::DEFAULT_EXPIRY_HOURS
        );

        $payment->fill([
            'amount' => (int) $amount,
            'currency' => $currency,
            'status' => 'pending',
            'payment_method' => $method,
            'merchant_order_reference' => $merchantRef,
            'gateway_status' => null,
            'gateway_response' => null,
            'ip_address' => $request->ip(),
            'expires_at' => now()->addHours($expiryHours),
        ])->save();

        try {
            $result = $this->gateways->driver($method)->initiatePayment($session, $user, [
                'merchant_ref' => $merchantRef,
                'amount' => (int) $amount,
                'currency' => $currency,
                'expiry_hours' => $expiryHours,
                'method' => $validated['channel'] ?? 'kiosk',
                'customer_mobile' => $validated['customer_mobile'] ?? null,
            ]);
        } catch (Throwable $e) {
            $this->logEvent($payment, 'failed', ['error' => $e->getMessage()]);
            \Log::warning('Payment initiation failed: '.$e->getMessage(), ['payment_id' => $payment->id]);

            return response()->json(['message' => 'Payment provider rejected the request.'], 502);
        }

        $payment->fill([
            'fawry_reference_number' => $method === 'fawry' ? $result->referenceNumber : $payment->fawry_reference_number,
            'instapay_reference_number' => $method === 'instapay' ? $result->referenceNumber : $payment->instapay_reference_number,
            'gateway_response' => $result->rawData,
        ])->save();

        $this->logEvent($payment, 'charge_created', $result->rawData);

        return response()->json([
            'payment_id' => $payment->id,
            'merchant_reference' => $merchantRef,
            'payment_method' => $method,
            'amount' => (int) $payment->amount,
            'currency' => $payment->currency,
            'fawry_code' => $method === 'fawry' ? $result->referenceNumber : null,
            'checkout_url' => $result->checkoutUrl,
            'deep_link' => $result->deepLink,
            'qr_code_payload' => $result->rawData['qr_string'] ?? null,
            'expires_at' => $payment->expires_at?->toISOString(),
        ], 201);
    }

    /**
     * GET /api/v1/live-sessions/{session}/pay/status
     *
     * Stored payment state + invite approval state. Pass ?refresh=1 to
     * re-query the provider; a confirmed-paid remote state flips a pending
     * payment to paid (webhook-equivalent server-to-server verification).
     */
    public function status(Request $request, LiveSession $session)
    {
        $user = $request->user();

        if ($user->role !== 'admin' && ! $this->canAccess($session, $user)) {
            abort(403);
        }

        $patientId = $user->role === 'admin'
            ? (int) $request->query('patient_id', $user->id)
            : $user->id;

        $payment = LiveSessionPayment::where('live_session_id', $session->id)
            ->where('patient_id', $patientId)
            ->first();

        if ($payment && $payment->status === 'pending' && $request->boolean('refresh')) {
            $payment = $this->refreshFromGateway($payment);
        }

        $invite = LiveSessionPatient::where('live_session_id', $session->id)
            ->where('patient_id', $patientId)
            ->first();

        return response()->json([
            'payment_status' => $payment?->status ?? 'none',
            'is_paid' => ($payment?->status ?? null) === 'paid',
            'merchant_reference' => $payment?->merchant_order_reference,
            'amount' => $payment ? (int) $payment->amount : null,
            'currency' => $payment?->currency,
            'payment_method' => $payment?->payment_method,
            'expires_at' => $payment?->expires_at?->toISOString(),
            'approval' => $invite ? [
                'status' => $invite->approval_status,
                'approved_at' => $invite->approved_at?->toISOString(),
                'rejection_reason' => $invite->rejection_reason,
            ] : null,
        ]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Re-query the provider for a pending payment. Marks paid only when the
     * provider confirms settlement AND the paid amount covers the fee.
     */
    protected function refreshFromGateway(LiveSessionPayment $payment): LiveSessionPayment
    {
        try {
            if (! $payment->payment_method || ! $payment->merchant_order_reference) {
                return $payment;
            }

            $result = $this->gateways
                ->driver($payment->payment_method)
                ->queryStatus($payment->merchant_order_reference);

            $payment->fill([
                'gateway_status' => $result->gatewayStatus ?? $result->status,
                'gateway_response' => $result->rawData,
            ])->save();

            if ($result->isPaid
                && ($result->amount === null || $result->amount >= (float) $payment->amount)) {
                $payment->fill(['status' => 'paid', 'paid_at' => now()])->save();
                $this->logEvent($payment, 'verified', $result->rawData);
            }

            return $payment->fresh();
        } catch (Throwable $e) {
            \Log::warning('Payment status refresh failed: '.$e->getMessage(), ['payment_id' => $payment->id]);

            return $payment;
        }
    }

    protected function logEvent(LiveSessionPayment $payment, string $event, mixed $payload): void
    {
        PaymentTransactionLog::create([
            'payment_id' => $payment->id,
            'event_type' => $event,
            'payload' => is_array($payload) ? $payload : ['data' => $payload],
        ]);
    }

    /**
     * Is the user entitled to see/pay this session?
     * (invited — or open session with no invites).
     */
    protected function canAccess(LiveSession $session, \App\Models\User $user): bool
    {
        if ($user->role === 'admin') {
            return $user->id === $session->admin_id;
        }

        if ($session->invites()->exists()) {
            return $session->invites()->where('patient_id', $user->id)->exists();
        }

        return $session->patient_id === null || $session->patient_id === $user->id;
    }
}
