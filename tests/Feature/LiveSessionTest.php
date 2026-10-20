<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\LiveSession;

class LiveSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_start_live_session()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        
        $response = $this->actingAs($admin)->postJson('/api/v1/live-sessions', [
            'title' => 'Test Session'
        ]);
        
        $response->assertStatus(200);
        $this->assertEquals('scheduled', $response->json('data.status'));
        $sessionId = $response->json('data.id');

        // Mock LiveKitService to not actually hit LiveKit API
        $this->mock(\App\Services\LiveKitService::class, function ($mock) {
            $mock->shouldReceive('createRoom')->once();
        });

        $startResponse = $this->actingAs($admin)->postJson("/api/v1/live-sessions/{$sessionId}/start");
        $startResponse->assertStatus(200);
        $this->assertEquals('live', $startResponse->json('data.status'));
    }

    public function test_patient_can_join_live_session()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $patient = User::factory()->create(['role' => 'patient']);
        
        $session = LiveSession::create([
            'admin_id' => $admin->id,
            'title' => 'Test',
            'status' => 'live',
            'livekit_room_name' => 'room_test'
        ]);

        // Dual-gate: free session still needs an approved invite when the
        // session requires admin approval (default true).
        \App\Models\LiveSessionPatient::create([
            'live_session_id' => $session->id,
            'patient_id' => $patient->id,
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);

        $this->mock(\App\Services\LiveKitService::class, function ($mock) {
            $mock->shouldReceive('createAccessToken')->once()->andReturn('fake_token_jwt');
        });

        $response = $this->actingAs($patient)->postJson("/api/v1/live-sessions/{$session->id}/join-token");
        
        $response->assertStatus(200);
        $this->assertEquals('fake_token_jwt', $response->json('data.token'));
        $this->assertFalse($response->json('data.participant.can_publish_audio'));
        $this->assertArrayNotHasKey('user', $response->json('data.participant')); // Anonymous for patient
    }

    public function test_patient_can_raise_hand_and_admin_can_approve()
    {
        \Illuminate\Support\Facades\Event::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $patient = User::factory()->create(['role' => 'patient']);
        
        $session = LiveSession::create([
            'admin_id' => $admin->id,
            'title' => 'Test',
            'status' => 'live',
            'livekit_room_name' => 'room_test'
        ]);

        $participant = \App\Models\LiveParticipant::create([
            'live_session_id' => $session->id,
            'user_id' => $patient->id,
            'role' => 'patient',
            'anonymous_label' => 'Participant 1',
            'livekit_identity' => 'participant_123',
            'status' => 'connected',
            'joined_at' => now(),
        ]);

        // Patient raises hand
        $raiseResponse = $this->actingAs($patient)->postJson("/api/v1/live-sessions/{$session->id}/raise-hand");
        $raiseResponse->assertStatus(200);
        $this->assertEquals('raised_hand', $participant->fresh()->status);
        
        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\LiveRaiseHandEvent::class);

        $handRequest = \App\Models\LiveRaiseHandRequest::where('participant_id', $participant->id)->first();
        
        // Mock LiveKitService for approval
        $this->mock(\App\Services\LiveKitService::class, function ($mock) {
            $mock->shouldReceive('updateParticipantPermissions')->once();
        });

        // Admin approves
        $approveResponse = $this->actingAs($admin)->postJson("/api/v1/raise-hand-requests/{$handRequest->id}/approve");
        $approveResponse->assertStatus(200);
        
        $this->assertEquals('speaking', $participant->fresh()->status);
        $this->assertTrue($participant->fresh()->can_publish_audio);
    }

    public function test_patient_can_send_private_chat_to_admin()
    {
        \Illuminate\Support\Facades\Event::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $patient = User::factory()->create(['role' => 'patient']);
        
        $session = LiveSession::create([
            'admin_id' => $admin->id,
            'title' => 'Test',
            'status' => 'live',
            'livekit_room_name' => 'room_test'
        ]);

        $participant = \App\Models\LiveParticipant::create([
            'live_session_id' => $session->id,
            'user_id' => $patient->id,
            'role' => 'patient',
            'anonymous_label' => 'Participant 1',
            'livekit_identity' => 'participant_123',
            'status' => 'connected',
            'joined_at' => now(),
        ]);

        $chatResponse = $this->actingAs($patient)->postJson("/api/v1/live-sessions/{$session->id}/chat", [
            'body' => 'Hello admin'
        ]);
        
        $chatResponse->assertStatus(200);
        
        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\LiveChatMessageEvent::class);

        // Verify patient cannot list chats
        $this->actingAs($patient)->getJson("/api/v1/live-sessions/{$session->id}/chat")->assertStatus(403);
        
        // Verify admin can list chats
        $this->actingAs($admin)->getJson("/api/v1/live-sessions/{$session->id}/chat")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }
}
