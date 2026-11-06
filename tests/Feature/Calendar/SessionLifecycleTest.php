<?php

namespace Tests\Feature\Calendar;

use App\Models\LiveParticipant;
use App\Models\LiveSession;
use App\Models\LiveSessionPatient;
use App\Models\User;
use App\Services\LiveKitService;
use App\Services\LiveKitWebhookVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * دورة حياة الجلسة على مستوى HTTP: لا موضع يكتب الحالة إلا الخدمة، فالمسارات القديمة
 * (start/end/pay/chat/webhook) تُقيَّم بقانون App\Enums\SessionStatus نفسه.
 *
 * الدفعة الأهم هنا: بوّابة LiveKit تأتي من الخارج، ولا يجوز أن تُنهي جلسة ألغاها الأدمن.
 */
class SessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->patient = User::factory()->create(['role' => 'patient']);

        Notification::fake();
    }

    /** @param  array<string, mixed>  $attributes */
    private function makeSession(string $status, array $attributes = []): LiveSession
    {
        return LiveSession::create(array_merge([
            'admin_id' => $this->admin->id,
            'title' => 'جلسة اختبار',
            'status' => $status,
            'scheduled_at' => '2026-11-06 19:00:00',
            'ends_at' => '2026-11-06 20:00:00',
            'livekit_room_name' => 'room_'.Str::random(10),
        ], $attributes));
    }

    private function invite(LiveSession $session, string $approval = 'approved'): LiveSessionPatient
    {
        return LiveSessionPatient::create([
            'live_session_id' => $session->id,
            'patient_id' => $this->patient->id,
            'approval_status' => $approval,
            'approved_at' => now(),
        ]);
    }

    /** إنهاء الغرفة كما يرسله LiveKit؛ التحقق من التوقيع شأن الـ verifier وحده. */
    private function finishRoom(LiveSession $session): TestResponse
    {
        $verifier = \Mockery::mock(LiveKitWebhookVerifier::class);
        $verifier->shouldReceive('verify')->andReturn([true, 'ok', ['sub' => $session->livekit_room_name]]);
        $this->instance(LiveKitWebhookVerifier::class, $verifier);

        return $this->postJson('/api/v1/webhooks/livekit', [
            'event' => 'room_finished',
            'room' => ['name' => $session->livekit_room_name],
        ]);
    }

    private function fakeLiveKit(): void
    {
        $this->mock(LiveKitService::class, function ($mock) {
            $mock->shouldReceive('createRoom')->andReturn(null);
            $mock->shouldReceive('deleteRoom')->andReturn(null);
        });
    }

    // ── بوّابة LiveKit ─────────────────────────────────────────────────────────

    public function test_the_room_finished_webhook_ends_a_live_session()
    {
        $session = $this->makeSession('live');
        LiveParticipant::create([
            'live_session_id' => $session->id,
            'user_id' => $this->patient->id,
            'role' => 'patient',
            'anonymous_label' => 'Participant 1',
            'livekit_identity' => 'participant_'.Str::random(8),
            'status' => 'connected',
            'joined_at' => now(),
        ]);

        $this->finishRoom($session)->assertStatus(200)->assertJson(['status' => 'ok']);

        $fresh = $session->fresh();
        $this->assertSame('ended', $fresh->status);
        $this->assertNotNull($fresh->ended_at);
        $this->assertSame('left', $fresh->participants()->first()->status, 'الحاضرون يُغلقون مع الغرفة');
    }

    public function test_the_room_finished_webhook_cannot_end_a_cancelled_session()
    {
        $session = $this->makeSession('cancelled', ['cancelled_at' => now()]);

        $this->finishRoom($session)->assertStatus(200);

        $fresh = $session->fresh();
        $this->assertSame('cancelled', $fresh->status, 'إلغاء الأدمن نهائي: لا webhook يُرجعه لاييفًا منتهيًا');
        $this->assertNull($fresh->ended_at);
    }

    public function test_the_room_finished_webhook_leaves_an_ended_session_alone()
    {
        $ended = now()->subHour();
        $session = $this->makeSession('ended', ['ended_at' => $ended]);

        $this->finishRoom($session)->assertStatus(200);

        $this->assertSame($ended->format('Y-m-d H:i:s'), $session->fresh()->ended_at->format('Y-m-d H:i:s'));
    }

    public function test_a_scheduled_session_is_closed_by_the_webhook_as_before()
    {
        // الجلسة التي لم تُشعل قط تُغلق بانتهاء غرفتها: سلوك المسار القديم محفوظ.
        $session = $this->makeSession('scheduled');

        $this->finishRoom($session)->assertStatus(200);

        $this->assertSame('ended', $session->fresh()->status);
    }

    // ── أزرار الأدمن ───────────────────────────────────────────────────────────

    public function test_start_accepts_scheduled_and_confirmed_but_nothing_terminal()
    {
        $this->fakeLiveKit();

        $this->actingAs($this->admin)->postJson("/api/v1/live-sessions/{$this->makeSession('scheduled')->id}/start")
            ->assertStatus(200)->assertJsonPath('data.status', 'live');

        $this->actingAs($this->admin)->postJson("/api/v1/live-sessions/{$this->makeSession('confirmed')->id}/start")
            ->assertStatus(200)->assertJsonPath('data.status', 'live', 'المؤكَّدة قابلة للبدء بعد توسيع الحالات');

        foreach (['cancelled', 'ended', 'missed'] as $status) {
            $this->actingAs($this->admin)
                ->postJson("/api/v1/live-sessions/{$this->makeSession($status)->id}/start")
                ->assertStatus(409, "a {$status} session cannot go live");
        }
    }

    public function test_end_refuses_a_terminal_session_instead_of_stamping_it_again()
    {
        $this->fakeLiveKit();

        $session = $this->makeSession('cancelled');

        $this->actingAs($this->admin)->postJson("/api/v1/live-sessions/{$session->id}/end")->assertStatus(409);
        $this->assertSame('cancelled', $session->fresh()->status);

        $live = $this->makeSession('live');
        $this->actingAs($this->admin)->postJson("/api/v1/live-sessions/{$live->id}/end")
            ->assertStatus(200)->assertJsonPath('data.status', 'ended');
        $this->actingAs($this->admin)->postJson("/api/v1/live-sessions/{$live->id}/end")->assertStatus(409);
    }

    public function test_only_the_owner_admin_can_move_a_session()
    {
        $this->fakeLiveKit();
        $other = User::factory()->create(['role' => 'admin']);
        $session = $this->makeSession('scheduled');

        $this->actingAs($other)->postJson("/api/v1/live-sessions/{$session->id}/start")->assertStatus(403);
        $this->assertSame('scheduled', $session->fresh()->status);
    }

    // ── ما يراه المريض ─────────────────────────────────────────────────────────

    public function test_the_patient_list_hides_cancelled_and_missed_sessions()
    {
        foreach (['scheduled', 'confirmed', 'live', 'cancelled', 'missed', 'ended'] as $status) {
            $this->invite($this->makeSession($status, ['title' => $status]));
        }

        $titles = $this->actingAs($this->patient)->getJson('/api/v1/live-sessions')
            ->assertStatus(200)
            ->collect('data')
            ->pluck('status')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['confirmed', 'live', 'scheduled'], $titles);
    }

    public function test_payment_follows_the_payable_allowlist()
    {
        $booked = $this->makeSession('confirmed', ['fee_amount' => 5000, 'fee_currency' => 'EGP']);
        $this->invite($booked);

        $this->actingAs($this->patient)->postJson("/api/v1/live-sessions/{$booked->id}/pay", ['reference' => 'REF-1'])
            ->assertStatus(200)->assertJsonPath('data.status', 'pending');

        $cancelled = $this->makeSession('cancelled', ['fee_amount' => 5000, 'fee_currency' => 'EGP']);
        $this->invite($cancelled);

        $this->actingAs($this->patient)->postJson("/api/v1/live-sessions/{$cancelled->id}/pay")
            ->assertStatus(400, 'ملغاة: لا باب دفع بعدها');
    }

    public function test_chat_is_open_only_while_the_session_is_live()
    {
        $confirmed = $this->makeSession('confirmed');
        $this->participant($confirmed);
        $this->actingAs($this->patient)->postJson("/api/v1/live-sessions/{$confirmed->id}/chat", ['body' => 'قبل الهواء'])
            ->assertStatus(403, 'قبل الهواء لا شات');

        $live = $this->makeSession('live');
        $this->participant($live);
        $this->actingAs($this->patient)->postJson("/api/v1/live-sessions/{$live->id}/chat", ['body' => 'مرحبًا'])
            ->assertStatus(200);

        $ended = $this->makeSession('ended');
        $this->participant($ended);
        $this->actingAs($this->patient)->postJson("/api/v1/live-sessions/{$ended->id}/chat", ['body' => 'بعد النهاية'])
            ->assertStatus(403);
    }

    public function test_raise_hand_requires_a_live_session()
    {
        $cancelled = $this->makeSession('cancelled');
        $this->participant($cancelled);

        $this->actingAs($this->patient)->postJson("/api/v1/live-sessions/{$cancelled->id}/raise-hand")->assertStatus(400);
    }

    // ── لوحة الأدمن ────────────────────────────────────────────────────────────

    public function test_the_dashboard_counts_every_status_in_one_query()
    {
        $this->makeSession('scheduled');
        $this->makeSession('scheduled');
        $this->makeSession('cancelled');
        $this->makeSession('missed');

        $counts = $this->actingAs($this->admin)->getJson('/api/v1/admin/stats')
            ->assertStatus(200)
            ->assertJsonPath('data.live_sessions.scheduled', 2)
            ->json('data.live_sessions');

        // المفاتيح الستة موجودة دائمًا ولو بصفر: لوحة جديدة لا تُفاجأ بحالة ظهرت لاحقًا.
        $this->assertSame(['scheduled', 'confirmed', 'live', 'ended', 'cancelled', 'missed'], array_keys($counts));
        $this->assertSame(1, $counts['cancelled']);
        $this->assertSame(1, $counts['missed']);
        $this->assertSame(0, $counts['ended']);
    }

    private function participant(LiveSession $session): LiveParticipant
    {
        return LiveParticipant::create([
            'live_session_id' => $session->id,
            'user_id' => $this->patient->id,
            'role' => 'patient',
            'anonymous_label' => 'Participant 1',
            'livekit_identity' => 'participant_'.Str::random(8),
            'status' => 'connected',
            'joined_at' => now(),
        ]);
    }
}
