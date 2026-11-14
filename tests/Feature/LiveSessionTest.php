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
        
        // Mock LiveKitService for approval (returns the real service contract)
        $this->mock(\App\Services\LiveKitService::class, function ($mock) {
            $mock->shouldReceive('updateParticipantPermissions')
                ->once()
                ->andReturn(['ok' => true, 'code' => null, 'attempts' => 1, 'can_publish_audio' => true]);
        });

        // Admin approves
        $approveResponse = $this->actingAs($admin)->postJson("/api/v1/raise-hand-requests/{$handRequest->id}/approve");
        $approveResponse->assertStatus(200);
        
        $this->assertEquals('speaking', $participant->fresh()->status);
        $this->assertTrue($participant->fresh()->can_publish_audio);
        $this->assertTrue($approveResponse->json('livekit_updated'));
    }

    /** موافقة الأدمن مع فشل تحديث LiveKit → النتيجة تُكشف بصراحة لا نجاح وهمي. */
    public function test_approve_surfaces_livekit_failure_honestly()
    {
        \Illuminate\Support\Facades\Event::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $patient = User::factory()->create(['role' => 'patient']);

        $session = LiveSession::create([
            'admin_id' => $admin->id, 'title' => 'Test', 'status' => 'live',
            'livekit_room_name' => 'room_test',
        ]);
        $participant = \App\Models\LiveParticipant::create([
            'live_session_id' => $session->id, 'user_id' => $patient->id, 'role' => 'patient',
            'anonymous_label' => 'Participant 1', 'livekit_identity' => 'participant_123',
            'status' => 'connected', 'joined_at' => now(),
        ]);
        $handRequest = \App\Models\LiveRaiseHandRequest::create([
            'live_session_id' => $session->id, 'participant_id' => $participant->id, 'status' => 'pending',
        ]);

        // فشل تحديث LiveKit (مثل عدم وصول السيرفر) — النتيجة يجب أن تظهر.
        $this->mock(\App\Services\LiveKitService::class, function ($mock) {
            $mock->shouldReceive('updateParticipantPermissions')
                ->once()
                ->andReturn(['ok' => false, 'code' => 'livekit_error', 'attempts' => 2, 'can_publish_audio' => true]);
        });

        $response = $this->actingAs($admin)->postJson("/api/v1/raise-hand-requests/{$handRequest->id}/approve");
        $response->assertStatus(200);
        $this->assertFalse($response->json('livekit_updated'));

        // حالة DB صحيحة (موافقة مسجلة) لكن صلاحية LiveKit غير محدثة — فصل صريح.
        $this->assertTrue($participant->fresh()->can_publish_audio);

        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\LiveHandResolvedEvent::class,
            fn ($e) => $e->status === 'approved' && $e->livekitUpdated === false);
        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\LiveParticipantPermissionEvent::class,
            fn ($e) => $e->canPublishAudio && $e->livekitUpdated === false);
    }

    /** سحب الصلاحية (mute) يبث participant.permission مع false. */
    public function test_mute_broadcasts_revoked_permission()
    {
        \Illuminate\Support\Facades\Event::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $patient = User::factory()->create(['role' => 'patient']);

        $session = LiveSession::create([
            'admin_id' => $admin->id, 'title' => 'Test', 'status' => 'live',
            'livekit_room_name' => 'room_test',
        ]);
        $participant = \App\Models\LiveParticipant::create([
            'live_session_id' => $session->id, 'user_id' => $patient->id, 'role' => 'patient',
            'anonymous_label' => 'Participant 1', 'livekit_identity' => 'participant_123',
            'status' => 'speaking', 'can_publish_audio' => true, 'joined_at' => now(),
        ]);

        $this->mock(\App\Services\LiveKitService::class, function ($mock) {
            $mock->shouldReceive('updateParticipantPermissions')
                ->once()
                ->andReturn(['ok' => true, 'code' => null, 'attempts' => 1, 'can_publish_audio' => false]);
        });

        $response = $this->actingAs($admin)->postJson("/api/v1/live-sessions/{$session->id}/participants/{$participant->id}/mute");
        $response->assertStatus(200);
        $this->assertTrue($response->json('livekit_updated'));
        $this->assertFalse($participant->fresh()->can_publish_audio);

        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\LiveParticipantPermissionEvent::class,
            fn ($e) => !$e->canPublishAudio && $e->livekitUpdated === true);
    }

    /** قناة الجلسة: أدمن الجلسة ومشارك منضم يُسمح لهما — وغير المشارك يُمنع. */
    public function test_live_session_channel_authorization()
    {
        // اختبار المسار الحقيقي (PusherBroadcaster) — null/log لا يتحقق من الصلاحية أصلًا.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret-that-is-at-least-32-chars-long',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        // الكولباكات تُسجل عند الإقلاع على درايفر البث الافتراضي (null في الاختبارات)،
        // لذا يُعاد تسجيلها على درايفر reverb بعد تحويل الإعداد.
        require base_path('routes/channels.php');

        $admin = User::factory()->create(['role' => 'admin']);
        $patient = User::factory()->create(['role' => 'patient']);
        $outsider = User::factory()->create(['role' => 'patient']);

        $session = LiveSession::create([
            'admin_id' => $admin->id, 'title' => 'Test', 'status' => 'live',
            'livekit_room_name' => 'room_test',
        ]);
        \App\Models\LiveParticipant::create([
            'live_session_id' => $session->id, 'user_id' => $patient->id, 'role' => 'patient',
            'anonymous_label' => 'Participant 1', 'livekit_identity' => 'participant_123',
            'status' => 'connected', 'joined_at' => now(), 'left_at' => null,
        ]);
        \App\Models\LiveParticipant::create([
            'live_session_id' => $session->id, 'user_id' => $outsider->id, 'role' => 'patient',
            'anonymous_label' => 'Participant 2', 'livekit_identity' => 'participant_456',
            'status' => 'left', 'joined_at' => now(), 'left_at' => now(),
        ]);

        $socket = '1234.5678'; // شكل socket_id الذي يقبله Pusher SDK (رقم.رقم).

        // أدمن الجلسة مسموح.
        $this->actingAs($admin)->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => $socket, 'channel_name' => "private-live.{$session->id}.admin",
        ])->assertOk();

        // مشارك منضم مسموح على قناة الجلسة.
        $this->actingAs($patient)->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => $socket, 'channel_name' => "private-live.{$session->id}",
        ])->assertOk();

        // مشارك غادر ممنوع.
        $this->actingAs($outsider)->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => $socket, 'channel_name' => "private-live.{$session->id}",
        ])->assertForbidden();

        // غير منضم ممنوع.
        $stranger = User::factory()->create(['role' => 'patient']);
        $this->actingAs($stranger)->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => $socket, 'channel_name' => "private-live.{$session->id}",
        ])->assertForbidden();
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

        // المشارك المُنضم يقرأ شات الجلسة (سلوك التطبيق الفعلي: المريض يجلب الشات
        // أثناء البثّ عبر GET chat) — وغير المشارك ممنوع (403).
        $this->actingAs($patient)->getJson("/api/v1/live-sessions/{$session->id}/chat")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $outsider = User::factory()->create(['role' => 'patient']);
        $this->actingAs($outsider)->getJson("/api/v1/live-sessions/{$session->id}/chat")
            ->assertStatus(403);
        // Verify admin can list chats
        $this->actingAs($admin)->getJson("/api/v1/live-sessions/{$session->id}/chat")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }
}
