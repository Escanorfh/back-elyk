<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\LiveSession;
use App\Models\LiveSessionPayment;
use Illuminate\Http\Request;

class PricingController extends Controller
{
    /**
     * GET /api/v1/admin/pricing
     */
    public function index()
    {
        return response()->json([
            'data' => [
                'default_session_price' => AppSetting::getValue('default_session_price'),
                'currency' => AppSetting::getValue('session_currency', 'EGP'),
                'fawry_expiry_hours' => AppSetting::getValue('fawry_expiry_hours', 24),
                'auto_refund_on_session_cancel' => AppSetting::getValue('auto_refund_on_session_cancel', true),
                'discount_presets' => AppSetting::getValue('discount_presets', []),
            ],
        ]);
    }

    /**
     * PUT /api/v1/admin/pricing
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'price' => 'required|integer|min:1',
            'currency' => 'nullable|string|size:3',
        ]);

        $oldPrice = AppSetting::getValue('default_session_price');
        $oldCurrency = AppSetting::getValue('session_currency', 'EGP');

        AppSetting::setValue('default_session_price', $validated['price'], 'integer', 'billing');

        if (array_key_exists('currency', $validated) && $validated['currency'] !== null) {
            AppSetting::setValue('session_currency', strtoupper($validated['currency']), 'string', 'billing');
        }

        \Log::info('Admin updated global session pricing.', [
            'admin_id' => $request->user()->id,
            'old_price' => $oldPrice,
            'new_price' => $validated['price'],
            'old_currency' => $oldCurrency,
            'new_currency' => AppSetting::getValue('session_currency', 'EGP'),
        ]);

        return response()->json([
            'message' => 'Pricing updated.',
            'data' => [
                'default_session_price' => AppSetting::getValue('default_session_price'),
                'currency' => AppSetting::getValue('session_currency', 'EGP'),
            ],
        ]);
    }

    /**
     * PUT /api/v1/admin/live-sessions/{session}/pricing
     *
     * Per-session override. Refused while the session has paid transactions —
     * settled amounts are immutable history.
     */
    public function updateSession(Request $request, LiveSession $session)
    {
        $validated = $request->validate([
            'fee_amount' => 'nullable|integer|min:0',
            'fee_currency' => 'nullable|string|size:3',
        ]);

        $hasPaid = LiveSessionPayment::where('live_session_id', $session->id)
            ->where('status', 'paid')
            ->exists();

        if ($hasPaid) {
            return response()->json(
                ['message' => 'Session price cannot be changed while paid transactions exist.'],
                422
            );
        }

        $oldAmount = $session->fee_amount;
        $oldCurrency = $session->fee_currency;

        $session->fill([
            'fee_amount' => array_key_exists('fee_amount', $validated) ? $validated['fee_amount'] : $session->fee_amount,
            'fee_currency' => array_key_exists('fee_currency', $validated) && $validated['fee_currency'] !== null
                ? strtoupper($validated['fee_currency'])
                : $session->fee_currency,
        ])->save();

        \Log::info('Admin updated session pricing.', [
            'admin_id' => $request->user()->id,
            'live_session_id' => $session->id,
            'old_fee_amount' => $oldAmount,
            'new_fee_amount' => $session->fee_amount,
            'old_fee_currency' => $oldCurrency,
            'new_fee_currency' => $session->fee_currency,
        ]);

        return response()->json([
            'message' => 'Session pricing updated.',
            'data' => $session->fresh(),
        ]);
    }
}
