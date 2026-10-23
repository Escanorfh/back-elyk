<?php

namespace Tests\Feature\Payment;

use App\Events\LiveSessionPaymentVerified;
use App\Models\LiveSession;
use App\Models\LiveSessionPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InstaPayWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected string $webhookSecret = 'TESTINSTAPAYSECRET';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.instapay.mode' => 'aggregator',
            'services.instapay.webhook_secret' => $this->webhookSecret,
        ]);
    }

    protected function makePayment(): LiveSessionPayment
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $patient = User::factory()->create(['role' => 'patient']);

        $session = LiveSession::create([
            'admin_id' => $admin->id,
            'title' => 'Test Session',
            'status' => 'scheduled',
            'livekit_room_name' => 'room_'.uniqid(),
            'fee_amount' => 500,
            'fee_currency' => 'EGP',
        ]);

        return LiveSessionPayment::create([
            'live_session_id' => $session->id,
            'patient_id' => $patient->id,
            'amount' => 500,
            'currency' => 'EGP',
            'status' => 'pending',
            'payment_method' => 'instapay',
            'merchant_order_reference' => 'ELY-SESS-'.$session->id.'-PAT-'.$patient->id.'-1234567890',
        ]);
    }

    /**
     * Sign the EXACT bytes postJson will send (ASCII-only payload keeps
     * PHP's json_encode deterministic).
     */
    protected function postSignedIpn(array $payload, ?string $signature = null)
    {
        $raw = json_encode($payload);
        $signature ??= hash_hmac('sha256', $raw, $this->webhookSecret);

        return $this->postJson('/api/v1/webhooks/instapay', $payload, [
            'X-Instapay-Signature' => $signature,
        ]);
    }

    protected function paidPayload(LiveSessionPayment $payment, array $overrides = []): array
    {
        return array_merge([
            'merchant_ref' => $payment->merchant_order_reference,
            'provider_ref' => 'IPN-TXN-999',
            'amount' => 500,
            'status' => 'settled',
            'timestamp' => time(),
        ], $overrides);
    }

    public function test_valid_hmac_marks_payment_paid()
    {
        Event::fake();
        Notification::fake();

        $payment = $this->makePayment();

        $this->postSignedIpn($this->paidPayload($payment))->assertStatus(200);

        $payment->refresh();
        $this->assertEquals('paid', $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertEquals('IPN-TXN-999', $payment->instapay_reference_number);

        Event::assertDispatched(LiveSessionPaymentVerified::class);
    }

    public function test_invalid_hmac_returns_403()
    {
        Event::fake();

        $payment = $this->makePayment();

        $this->postSignedIpn($this->paidPayload($payment), 'deadbeef')->assertStatus(403);

        $this->assertEquals('pending', $payment->fresh()->status);
        Event::assertNotDispatched(LiveSessionPaymentVerified::class);
    }

    public function test_stale_timestamp_is_rejected()
    {
        Event::fake();

        $payment = $this->makePayment();

        $this->postSignedIpn($this->paidPayload($payment, [
            'timestamp' => time() - 3600,
        ]))->assertStatus(400);

        $this->assertEquals('pending', $payment->fresh()->status);
        Event::assertNotDispatched(LiveSessionPaymentVerified::class);
    }

    public function test_under_amount_is_held_for_review()
    {
        Event::fake();

        $payment = $this->makePayment();

        $response = $this->postSignedIpn($this->paidPayload($payment, ['amount' => 100]));
        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Held for review.');

        $this->assertEquals('pending', $payment->fresh()->status);
        Event::assertNotDispatched(LiveSessionPaymentVerified::class);
    }
}
