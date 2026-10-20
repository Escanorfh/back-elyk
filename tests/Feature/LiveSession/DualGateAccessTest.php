<?php

namespace Tests\Feature\LiveSession;

use App\Models\LiveSession;
use App\Models\LiveSessionPatient;
use App\Models\LiveSessionPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DualGateAccessTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $patient;

    protected LiveSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->patient = User::factory()->create(['role' => 'patient']);

        $this->session = LiveSession::create([
            'admin_id' => $this->admin->id,
            'title' => 'Dual Gate Session',
            'status' => 'live',
            'livekit_room_name' => 'room_'.uniqid(),
            'fee_amount' => 500,
            'fee_currency' => 'EGP',
            'requires_admin_approval' => true,
        ]);

        LiveSessionPatient::create([
            'live_session_id' => $this->session->id,
            'patient_id' => $this->patient->id,
            'approval_status' => 'pending_approval',
        ]);
    }

    protected function makePayment(string $status = 'pending'): LiveSessionPayment
    {
        return LiveSessionPayment::create([
            'live_session_id' => $this->session->id,
            'patient_id' => $this->patient->id,
            'amount' => 500,
            'currency' => 'EGP',
            'status' => $status,
            'payment_method' => 'fawry',
            'merchant_order_reference' => 'ELY-SESS-'.$this->session->id.'-PAT-'.$this->patient->id.'-'.uniqid(),
            'paid_at' => $status === 'paid' ? now() : null,
        ]);
    }

    protected function mockToken(): void
    {
        $this->mock(\App\Services\LiveKitService::class, function ($mock) {
            $mock->shouldReceive('createAccessToken')->once()->andReturn('fake_token_jwt');
        });
    }

    public function test_patient_cannot_join_without_paying()
    {
        $response = $this->actingAs($this->patient)
            ->postJson("/api/v1/live-sessions/{$this->session->id}/join-token");

        $response->assertStatus(402);
    }

    public function test_patient_cannot_join_after_paying_without_approval()
    {
        $this->makePayment('paid');

        $response = $this->actingAs($this->patient)
            ->postJson("/api/v1/live-sessions/{$this->session->id}/join-token");

        $response->assertStatus(403);
        $response->assertSee('administrative approval');
    }

    public function test_patient_can_join_after_paying_and_approval()
    {
        $this->mockToken();
        $this->makePayment('paid');

        LiveSessionPatient::where('live_session_id', $this->session->id)
            ->where('patient_id', $this->patient->id)
            ->update(['approval_status' => 'approved', 'approved_at' => now()]);

        $response = $this->actingAs($this->patient)
            ->postJson("/api/v1/live-sessions/{$this->session->id}/join-token");

        $response->assertStatus(200);
        $this->assertEquals('fake_token_jwt', $response->json('data.token'));
    }

    public function test_rejected_patient_cannot_join_even_when_paid()
    {
        $this->makePayment('paid');

        LiveSessionPatient::where('live_session_id', $this->session->id)
            ->where('patient_id', $this->patient->id)
            ->update(['approval_status' => 'rejected', 'rejection_reason' => 'No slots']);

        $this->actingAs($this->patient)
            ->postJson("/api/v1/live-sessions/{$this->session->id}/join-token")
            ->assertStatus(403);
    }
}
