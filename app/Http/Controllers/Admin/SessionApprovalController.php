<?php

namespace App\Http\Controllers\Admin;

use App\Events\AdminApprovalQueueUpdatedEvent;
use App\Events\SessionApprovedEvent;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\LiveSession;
use App\Models\LiveSessionPatient;
use App\Models\LiveSessionPayment;
use App\Models\PaymentTransactionLog;
use App\Models\User;
use App\Notifications\SessionApprovedNotification;
use App\Services\Payment\PaymentGatewayManager;
use Illuminate\Http\Request;

class SessionApprovalController extends Controller
{
    public function __construct(protected PaymentGatewayManager $gateways) {}

    /**
     * GET /api/v1/admin/sessions/approval-queue
     *
     * Invites stuck at pending_approval where the patient already paid.
     */
    public function queue(Request $request)
    {
        $invites = LiveSessionPatient::with([
                'session:id,title,status,scheduled_at,fee_amount,fee_currency',
                'patient:id,name,email',
            ])
            ->where('approval_status', 'pending_approval')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('live_session_payments')
                    ->whereColumn('live_session_payments.live_session_id', 'live_session_patients.live_session_id')
                    ->whereColumn('live_session_payments.patient_id', 'live_session_patients.patient_id')
                    ->where('live_session_payments.status', 'paid');
            })
            ->orderByDesc('id')
            ->get();

        $payments = LiveSessionPayment::where('status', 'paid')
            ->whereIn('live_session_id', $invites->pluck('live_session_id'))
            ->whereIn('patient_id', $invites->pluck('patient_id'))
            ->get()
            ->keyBy(fn ($payment) => $payment->live_session_id.':'.$payment->patient_id);

        $data = $invites->map(function (LiveSessionPatient $invite) use ($payments) {
            $payment = $payments->get($invite->live_session_id.':'.$invite->patient_id);

            return [
                'live_session_id' => $invite->live_session_id,
                'patient_id' => $invite->patient_id,
                'approval_status' => $invite->approval_status,
                'session' => $invite->session,
                'patient' => $invite->patient,
                'payment' => $payment ? [
                    'id' => $payment->id,
                    'payment_method' => $payment->payment_method,
                    'amount' => (int) $payment->amount,
                    'currency' => $payment->currency,
                    'provider_reference' => $payment->fawry_reference_number
                        ?? $payment->instapay_reference_number
                        ?? $payment->provider_reference,
                    'paid_at' => $payment->paid_at?->toISOString(),
                ] : null,
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/v1/admin/sessions/{session}/patients/{patient}/approve
     */
    public function approve(Request $request, LiveSession $session, User $patient)
    {
        $invite = $this->findInvite($session, $patient);

        $invite->fill([
            'approval_status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ])->save();

        SessionApprovedEvent::dispatch($session, $patient);
        AdminApprovalQueueUpdatedEvent::dispatch(
            AdminApprovalQueueUpdatedEvent::pendingCount(),
            $session->id,
            'approved'
        );
        $patient->notify(new SessionApprovedNotification($session));

        return response()->json([
            'message' => 'Session approved.',
            'data' => $invite->fresh(),
        ]);
    }

    /**
     * POST /api/v1/admin/sessions/{session}/patients/{patient}/reject
     *
     * Optional opt-ins: `refund: true` attempts a gateway refund of the paid
     * charge; `new_scheduled_at` reschedules the session in the same call.
     */
    public function reject(Request $request, LiveSession $session, User $patient)
    {
        $validated = $request->validate([
            'rejection_reason' => 'nullable|string|max:1000',
            'refund' => 'nullable|boolean',
            'new_scheduled_at' => 'nullable|date',
        ]);

        $invite = $this->findInvite($session, $patient);

        $invite->fill([
            'approval_status' => 'rejected',
            'approved_by' => $request->user()->id,
            'approved_at' => null,
            'rejection_reason' => $validated['rejection_reason'] ?? null,
        ])->save();

        $refundResult = null;

        if ($request->boolean('refund')) {
            $refundResult = $this->attemptRefund($session, $patient);
        }

        if (! empty($validated['new_scheduled_at'])) {
            $session->fill(['scheduled_at' => $validated['new_scheduled_at']])->save();
        }

        AdminApprovalQueueUpdatedEvent::dispatch(
            AdminApprovalQueueUpdatedEvent::pendingCount(),
            $session->id,
            'rejected'
        );

        return response()->json([
            'message' => 'Session rejected.',
            'data' => $invite->fresh(),
            'refund' => $refundResult,
        ]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    protected function findInvite(LiveSession $session, User $patient): LiveSessionPatient
    {
        $invite = LiveSessionPatient::where('live_session_id', $session->id)
            ->where('patient_id', $patient->id)
            ->first();

        if (! $invite) {
            abort(404, 'Patient is not invited to this session.');
        }

        return $invite;
    }

    /**
     * Gateway refund of the paid charge, honoring the auto-refund setting.
     * Returns a status string for the API response; never throws.
     */
    protected function attemptRefund(LiveSession $session, User $patient): string
    {
        try {
            if (! AppSetting::getValue('auto_refund_on_session_cancel', true)) {
                return 'skipped_by_settings';
            }

            $payment = LiveSessionPayment::where('live_session_id', $session->id)
                ->where('patient_id', $patient->id)
                ->where('status', 'paid')
                ->first();

            if (! $payment || ! $payment->payment_method) {
                return 'nothing_to_refund';
            }

            $ok = $this->gateways->driver($payment->payment_method)->refund($payment);

            if (! $ok) {
                \Log::warning('Refund attempt failed after rejection.', [
                    'payment_id' => $payment->id,
                ]);

                return 'failed';
            }

            $payment->fill(['status' => 'refunded'])->save();

            PaymentTransactionLog::create([
                'payment_id' => $payment->id,
                'event_type' => 'refunded',
                'payload' => ['trigger' => 'admin_rejection'],
            ]);

            return 'refunded';
        } catch (\Throwable $e) {
            \Log::warning('Refund attempt errored after rejection: '.$e->getMessage());

            return 'failed';
        }
    }
}
