<?php

namespace Tests\Feature\Payment;

use App\Events\LiveSessionPaymentVerified;
use App\Models\LiveSession;
use App\Models\LiveSessionPayment;
use App\Models\User;
use App\Notifications\NewPaymentNeedsApprovalNotification;
use App\Notifications\PaymentVerifiedNotification;
use App\Services\Payment\FawryPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FawryWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected string $merchantCode = 'TESTMERCHANT';

    protected string $securityKey = 'TESTSECURITYKEY123';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.fawry.merchant_code' => $this->merchantCode,
            'services.fawry.security_key' => $this->securityKey,
            'services.fawry.base_url' => 'https://atfawry.fawrystaging.com',
        ]);
    }

    protected function makePayment(array $overrides = []): LiveSessionPayment
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

        return LiveSessionPayment::create(array_merge([
            'live_session_id' => $session->id,
            'patient_id' => $patient->id,
            'amount' => 500,
            'currency' => 'EGP',
            'status' => 'pending',
            'payment_method' => 'fawry',
            'merchant_order_reference' => 'ELY-SESS-'.$session->id.'-PAT-'.$patient->id.'-1234567890',
        ], $overrides));
    }

    protected function signedPayload(LiveSessionPayment $payment, array $overrides = []): array
    {
        $payload = array_merge([
            'merchantRefNumber' => $payment->merchant_order_reference,
            'fawryRefNumber' => 'FAWRYREF123',
            'orderAmount' => '500.00',
            'paidAmount' => '500.00',
            'orderStatus' => 'PAID',
            'paymentMethod' => 'PAYATFAWRY',
        ], $overrides);

        $payload['signature'] = FawryPaymentService::generateWebhookSignature(
            $this->merchantCode,
            $payload['merchantRefNumber'],
            $payload['fawryRefNumber'],
            $payload['orderAmount'],
            $payload['paidAmount'],
            $payload['orderStatus'],
            $payload['paymentMethod'],
            $this->securityKey,
        );

        return $payload;
    }

    public function test_valid_signature_marks_payment_paid()
    {
        Event::fake();
        Notification::fake();

        $payment = $this->makePayment();

        $response = $this->postJson('/api/v1/webhooks/fawry', $this->signedPayload($payment));

        $response->assertStatus(200);

        $payment->refresh();
        $this->assertEquals('paid', $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertEquals('FAWRYREF123', $payment->fawry_reference_number);
        $this->assertEquals('PAID', $payment->gateway_status);

        Event::assertDispatched(LiveSessionPaymentVerified::class);
        Notification::assertSentTo($payment->patient, PaymentVerifiedNotification::class);
        Notification::assertSentTo($payment->session->admin, NewPaymentNeedsApprovalNotification::class);
    }

    public function test_invalid_signature_returns_403_and_keeps_pending()
    {
        Event::fake();

        $payment = $this->makePayment();

        $payload = $this->signedPayload($payment);
        $payload['signature'] = 'tampered'.$payload['signature'];

        $response = $this->postJson('/api/v1/webhooks/fawry', $payload);

        $response->assertStatus(403);
        $this->assertEquals('pending', $payment->fresh()->status);

        Event::assertNotDispatched(LiveSessionPaymentVerified::class);
    }

    public function test_duplicate_webhook_is_idempotent()
    {
        Event::fake();

        $payment = $this->makePayment();
        $payload = $this->signedPayload($payment);

        $this->postJson('/api/v1/webhooks/fawry', $payload)->assertStatus(200);
        $second = $this->postJson('/api/v1/webhooks/fawry', $payload);

        $second->assertStatus(200);
        $second->assertJsonPath('message', 'Already processed.');

        Event::assertDispatched(LiveSessionPaymentVerified::class, 1);
        $this->assertEquals('paid', $payment->fresh()->status);
    }

    public function test_verified_non_settled_status_marks_failed()
    {
        Event::fake();

        $payment = $this->makePayment();
        $payload = $this->signedPayload($payment, [
            'orderStatus' => 'FAILED',
            'paidAmount' => '0.00',
        ]);

        $this->postJson('/api/v1/webhooks/fawry', $payload)->assertStatus(200);

        $this->assertEquals('failed', $payment->fresh()->status);
        Event::assertNotDispatched(LiveSessionPaymentVerified::class);
    }
}
