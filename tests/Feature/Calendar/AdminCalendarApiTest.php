<?php

namespace Tests\Feature\Calendar;

use App\Models\AppSetting;
use App\Models\CalendarEvent;
use App\Models\Disorder;
use App\Models\LiveSession;
use App\Models\PatientPath;
use App\Models\PathStep;
use App\Models\PersonalTask;
use App\Models\Reminder;
use App\Models\User;
use App\Models\UserSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * واجهات الإدارة (§5): التقويم المجمَّع، أدوات اختيار الوقت، الانتقالات الوسيطة،
 * إسناد عناصر للمريض وجدولة خطوات المسار. البوابة هنا «من يملك ماذا»، والقانون في الخدمة.
 */
class AdminCalendarApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $otherAdmin;

    private User $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->otherAdmin = User::factory()->create(['role' => 'admin']);
        $this->patient = User::factory()->create(['role' => 'patient']);

        UserSettings::create(['user_id' => $this->patient->id, 'timezone' => 'Africa/Cairo']);

        Notification::fake();
    }

    private function openWidening(): void
    {
        AppSetting::setValue('calendar.status_widening_enabled', true, 'boolean', 'calendar');
    }

    /** جلسة خام بميعاد صريح — بلا مرور بالخدمة، لأن الاختبار عن البوابة لا عن الإنشاء. */
    private function rawSession(int $adminId, ?int $patientId, string $start, ?string $end = null, string $status = 'scheduled'): LiveSession
    {
        return LiveSession::create([
            'admin_id' => $adminId,
            'patient_id' => $patientId,
            'title' => 'جلسة '.substr($start, 0, 13),
            'status' => $status,
            'scheduled_at' => $start,
            'ends_at' => $end,
            'timezone' => 'UTC',
            'livekit_room_name' => 'room_'.Str::random(10),
        ]);
    }

    private function step(string $status = 'active'): PathStep
    {
        $disorder = Disorder::create(['name' => 'D1', 'name_ar' => 'D1']);
        $path = PatientPath::create([
            'patient_id' => $this->patient->id,
            'disorder_id' => $disorder->id,
            'status' => 'active',
        ]);

        return PathStep::create([
            'patient_path_id' => $path->id,
            'order' => 1,
            'title' => 'واجب صوتي',
            'status' => $status,
        ]);
    }

    public function test_the_admin_calendar_covers_own_sessions_and_a_selected_patient()
    {
        $this->rawSession($this->admin->id, $this->patient->id, '2026-11-05 14:00:00', '2026-11-05 15:00:00');
        $this->rawSession($this->otherAdmin->id, $this->patient->id, '2026-11-05 18:00:00');

        $own = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/calendar?from=2026-11-01&to=2026-11-08&types[]=live_session')
            ->assertStatus(200)
            ->json('data.items');
        $this->assertCount(1, $own, 'جدول الأدمن جلسةَ نفسه فقط');

        // patient_id يحصر كل المصادر في مريض واحد — بما فيها جلسات غيره.
        $scoped = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/calendar?patient_id='.$this->patient->id.'&from=2026-11-01&to=2026-11-08&types[]=live_session')
            ->assertStatus(200)
            ->json('data.items');
        $this->assertCount(2, $scoped);
    }

    public function test_a_patient_cannot_reach_the_admin_calendar_endpoints()
    {
        $this->actingAs($this->patient)->getJson('/api/v1/admin/calendar')->assertStatus(403);
        $this->actingAs($this->patient)->getJson('/api/v1/admin/calendar/availability')->assertStatus(403);
        $this->actingAs($this->patient)->postJson('/api/v1/admin/patients/'.$this->patient->id.'/tasks', ['title' => 'x', 'due_at' => now()->toDateTimeString()])->assertStatus(403);
        $this->assertSame(0, PersonalTask::query()->count());
    }

    public function test_intermediate_transitions_stay_closed_until_the_gate_is_opened()
    {
        $session = $this->rawSession($this->admin->id, $this->patient->id, '2026-11-05 14:00:00');

        // البوابة مغلقة افتراضيًا: الحالات التي لم يعرفها التطبيق قبل التقويم لا تُكتب.
        $this->actingAs($this->admin)->postJson("/api/v1/live-sessions/{$session->id}/confirm")->assertStatus(409);
        $this->actingAs($this->admin)->postJson("/api/v1/live-sessions/{$session->id}/cancel")->assertStatus(409);
        $this->actingAs($this->admin)->postJson("/api/v1/live-sessions/{$session->id}/mark-missed")->assertStatus(409);
        $this->assertSame('scheduled', $session->fresh()->status);

        $this->openWidening();

        $confirmed = $this->actingAs($this->admin)->postJson("/api/v1/live-sessions/{$session->id}/confirm")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'confirmed')
            ->json('data');
        $this->assertNotNull($confirmed['confirmed_at']);

        $cancelled = $this->actingAs($this->admin)
            ->postJson("/api/v1/live-sessions/{$session->id}/cancel", ['reason' => 'ظروف طارئة'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled')
            ->json('data');
        $this->assertNotNull($cancelled['cancelled_at']);
        // التذكيرات المعلَّقة تموت مع الجلسة الملغاة.
        $this->assertSame(0, Reminder::query()->where('notifiable_type', LiveSession::class)->where('status', 'pending')->count());
    }

    public function test_only_the_owning_admin_can_move_a_session()
    {
        $this->openWidening();
        $session = $this->rawSession($this->admin->id, $this->patient->id, '2026-11-05 14:00:00');

        $this->actingAs($this->otherAdmin)->postJson("/api/v1/live-sessions/{$session->id}/confirm")->assertStatus(403);
        $this->actingAs($this->otherAdmin)->postJson("/api/v1/live-sessions/{$session->id}/cancel")->assertStatus(403);
        $this->actingAs($this->otherAdmin)->postJson("/api/v1/live-sessions/{$session->id}/reschedule", ['scheduled_at' => '2026-11-07 14:00:00'])->assertStatus(403);
        $this->actingAs($this->patient)->postJson("/api/v1/live-sessions/{$session->id}/confirm")->assertStatus(403);

        $this->assertSame('scheduled', $session->fresh()->status);
    }

    public function test_rescheduling_keeps_history_and_moves_the_reminder_with_the_time()
    {
        // السلعة القديمة محفوظة: إنشاء الجلسة كان 200 قبل التقويم، فلا يُكسر العميل بتغيير الرمز.
        $created = $this->actingAs($this->admin)->postJson('/api/v1/live-sessions', [
            'title' => 'جلسة متابعة',
            'scheduled_at' => '2026-11-05 14:00:00',
            'ends_at' => '2026-11-05 15:00:00',
            'timezone' => 'UTC',
            'patient_id' => $this->patient->id,
            'reminder_offsets' => [60],
        ])->assertStatus(200)->json('data');

        $moved = $this->actingAs($this->admin)
            ->postJson("/api/v1/live-sessions/{$created['id']}/reschedule", ['scheduled_at' => '2026-11-07 14:00:00', 'ends_at' => '2026-11-07 15:00:00'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'scheduled')
            ->json('data');

        // الجلسة نموذج موجود: تُصدَّر بصيغته الافتراضية (النقاط الستة و Z)، لا بصيغة التقويم.
        $this->assertSame('2026-11-05T14:00:00.000000Z', $moved['previous_scheduled_at']);
        $this->assertNotNull($moved['rescheduled_at']);
        $this->assertSame($this->admin->id, $moved['rescheduled_by']);

        // التذكير ينتقل مع الموعد: لا تذكير يتيم على ساعة قديمة.
        $reminder = Reminder::query()->where('notifiable_type', LiveSession::class)->where('status', 'pending')->first();
        $this->assertNotNull($reminder);
        $this->assertSame(
            CarbonImmutable::parse($moved['scheduled_at'])->timestamp - 3600,
            $reminder->reminds_at->timestamp
        );
    }

    public function test_a_conflicting_time_is_refused_on_write_and_reported_on_preview()
    {
        $this->rawSession($this->admin->id, $this->patient->id, '2026-11-05 14:00:00');

        $write = $this->actingAs($this->admin)->postJson('/api/v1/live-sessions', [
            'title' => 'تعارض',
            'scheduled_at' => '2026-11-05 14:00:00',
            'timezone' => 'UTC',
            'patient_id' => $this->patient->id,
        ])->assertStatus(409);
        $this->assertCount(1, $write->json('conflicts.patients'));

        // الفحص المسبق يعطي نفس التقرير بلا كتابة.
        $preview = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/calendar/conflicts?scheduled_at=2026-11-05 14:00:00&timezone=UTC&patient_ids[]='.$this->patient->id)
            ->assertStatus(200)
            ->assertJsonPath('data.has_conflict', true)
            ->json('data');
        $this->assertCount(1, $preview['conflicts']['patients']);
        $this->assertSame(1, LiveSession::query()->count(), 'الفحص لا ينشئ شيئًا');

        // الأدمن يصرّح بتخطي الفحص حين يعرف السبب.
        $this->actingAs($this->admin)->postJson('/api/v1/live-sessions', [
            'title' => 'تعارض مقصود',
            'scheduled_at' => '2026-11-05 14:00:00',
            'timezone' => 'UTC',
            'patient_id' => $this->patient->id,
            'allow_conflict' => true,
        ])->assertStatus(200);
    }

    public function test_availability_returns_busy_items_and_the_gaps_between_them()
    {
        $this->rawSession($this->admin->id, $this->patient->id, '2026-11-05 14:00:00', '2026-11-05 15:00:00');

        $data = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/calendar/availability?patient_id='.$this->patient->id.'&from=2026-11-05 12:00:00&to=2026-11-05 17:00:00&timezone=UTC')
            ->assertStatus(200)
            ->assertJsonPath('data.timezone', 'UTC')
            ->json('data');

        $this->assertSame('live_session', $data['busy'][0]['source']);
        $this->assertSame([
            ['starts_at' => '2026-11-05T12:00:00+00:00', 'ends_at' => '2026-11-05T14:00:00+00:00'],
            ['starts_at' => '2026-11-05T15:00:00+00:00', 'ends_at' => '2026-11-05T17:00:00+00:00'],
        ], $data['free']);
    }

    public function test_availability_needs_a_real_window_and_a_real_patient()
    {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/calendar/availability?patient_id='.$this->patient->id.'&from=2026-11-05 17:00:00&to=2026-11-05 12:00:00')
            ->assertStatus(422);
        $this->actingAs($this->admin)->getJson('/api/v1/admin/calendar/availability?patient_id=999999&from=2026-11-05 12:00:00&to=2026-11-05 17:00:00')->assertStatus(422);
    }

    public function test_an_admin_assigns_a_task_to_a_patient_and_the_patient_keeps_the_choice()
    {
        $task = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/patients/'.$this->patient->id.'/tasks', [
                'title' => 'تمرين تنفس',
                'due_at' => '2026-11-05 09:00:00',
                'timezone' => 'UTC',
                // الواجب العلاجي يسكن path_steps، فلا يجوز أن يظهر كفئة على مهمة شخصية.
                'category' => 'daily_practice',
                'reminder_offsets' => [30],
            ])
            ->assertStatus(201)
            ->json('data');

        $this->assertSame($this->patient->id, $task['user_id'], 'الملكية للمريض');
        $this->assertSame($this->admin->id, $task['created_by'], 'النسبة للمعالج');
        $this->assertSame(1, Reminder::query()->where('notifiable_type', PersonalTask::class)->where('status', 'pending')->count());

        // النص ملك المعالج: المريض يعدّل القرار لا الكلام.
        $this->actingAs($this->patient)->putJson('/api/v1/me/tasks/'.$task['id'], ['title' => 'تغيير'])->assertStatus(403);
        $this->actingAs($this->patient)->postJson('/api/v1/me/tasks/'.$task['id'].'/complete')->assertStatus(200);

        // الأدمن صاحب النسبة يصحّح ما كتبه هو.
        $this->actingAs($this->admin)->putJson('/api/v1/admin/personal-tasks/'.$task['id'], ['title' => 'تمرين تنفس بطيء'])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'تمرين تنفس بطيء');

        // الحالة قرار المريض وحده: صاحب النسبة يعدّل الكلام، لا يشهد أنه أنجز التمرين.
        $this->actingAs($this->admin)->putJson('/api/v1/admin/personal-tasks/'.$task['id'], ['status' => 'pending'])->assertStatus(403);
        $this->assertSame('completed', PersonalTask::find($task['id'])->status);

        // أدمن آخر لا يلمس عنصرًا لم ينسبه.
        $this->actingAs($this->otherAdmin)->putJson('/api/v1/admin/personal-tasks/'.$task['id'], ['title' => 'اختراق'])->assertStatus(403);
        $this->actingAs($this->otherAdmin)->deleteJson('/api/v1/admin/personal-tasks/'.$task['id'])->assertStatus(403);
    }

    public function test_an_admin_assigns_an_event_to_a_patient()
    {
        $event = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/patients/'.$this->patient->id.'/events', [
                'title' => 'موعد أشعة',
                'starts_at' => '2026-11-06 10:00:00',
                'ends_at' => '2026-11-06 11:00:00',
                'timezone' => 'UTC',
                'type' => 'other_clinical_appointment',
            ])
            ->assertStatus(201)
            ->json('data');

        $this->assertSame($this->patient->id, $event['user_id']);
        $this->assertSame($this->admin->id, $event['created_by']);

        // يظهر في تقويم المريض وهو للقراءة فقط عنده.
        $this->actingAs($this->patient)->getJson('/api/v1/me/events/'.$event['id'])->assertStatus(200)->assertJsonPath('data.editable_by_user', false);
        $this->actingAs($this->patient)->putJson('/api/v1/me/events/'.$event['id'], ['title' => 'تغيير'])->assertStatus(403);

        $this->actingAs($this->admin)->deleteJson('/api/v1/admin/calendar-events/'.$event['id'])->assertStatus(200);
        $this->assertDatabaseMissing('calendar_events', ['id' => $event['id']]);
    }

    public function test_assignment_targets_patients_only_and_never_a_second_admin()
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/patients/'.$this->otherAdmin->id.'/tasks', [
                'title' => 'خطأ',
                'due_at' => '2026-11-05 09:00:00',
            ])
            ->assertStatus(404);
        $this->assertSame(0, PersonalTask::query()->count());

        // مريض غير موجود: الرابط نموذج محجوب، فيكون 404 لا رسالة تحقق.
        $this->actingAs($this->admin)->postJson('/api/v1/admin/patients/424242/tasks', ['title' => 'x'])->assertStatus(404);
    }

    public function test_a_step_is_scheduled_cleared_and_guarded_by_its_own_state()
    {
        $step = $this->step();

        $scheduled = $this->actingAs($this->admin)
            ->putJson('/api/v1/admin/path-steps/'.$step->id.'/schedule', [
                'due_at' => '2026-11-05 09:00:00',
                'timezone' => 'UTC',
                'priority' => 'high',
                'category' => 'therapeutic_homework',
                'reminder_offsets' => [120],
            ])
            ->assertStatus(200)
            // خطوة المسار نموذج موجود قبل التقويم: تبقى على صيغة افتراضياته ولا تُعاد تسميتها.
            ->assertJsonPath('data.due_at', '2026-11-05T09:00:00.000000Z')
            ->json('data');
        $this->assertSame($this->admin->id, $scheduled['assigned_by']);
        $this->assertSame(1, Reminder::query()->where('notifiable_type', PathStep::class)->where('status', 'pending')->count());

        // سحب الموعد يعيدها «بلا تاريخ» ويطفئ تذكيراتها.
        $this->actingAs($this->admin)->deleteJson('/api/v1/admin/path-steps/'.$step->id.'/schedule')
            ->assertStatus(200)
            ->assertJsonPath('data.due_at', null);
        $this->assertSame(0, Reminder::query()->where('notifiable_type', PathStep::class)->where('status', 'pending')->count());

        // خطوة سُلِّمت لا موعد لها في المستقبل.
        $done = $this->step('completed');
        $this->actingAs($this->admin)->putJson('/api/v1/admin/path-steps/'.$done->id.'/schedule', ['due_at' => '2026-11-05 09:00:00'])->assertStatus(422);
    }

    public function test_step_scheduling_is_closed_to_the_patient()
    {
        $step = $this->step();

        $this->actingAs($this->patient)->putJson('/api/v1/admin/path-steps/'.$step->id.'/schedule', ['due_at' => '2026-11-05 09:00:00'])->assertStatus(403);
        $this->assertNull($step->fresh()->due_at);
    }

    public function test_the_admin_calendar_refuses_a_foreign_patient_scope()
    {
        // patient_id فلتر استعلام لا رابط نموذج: المعرّف غير الموجود مًدخلٌ خاطئ (422)،
        // أما الموجود لكنه ليس مريضًا فمصدر محجوب (404).
        $this->actingAs($this->admin)->getJson('/api/v1/admin/calendar?patient_id=424242')->assertStatus(422);
        $this->actingAs($this->admin)->getJson('/api/v1/admin/calendar?patient_id='.$this->admin->id)->assertStatus(404, 'المصدر المحصور مريض لا أدمن');
    }

    public function test_a_therapist_created_event_still_respects_the_patient_window_default()
    {
        // لا timezone في الطلب ← منطقة المريض المعلَنة هي التي تُفسَّر بها اللحظة.
        $event = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/patients/'.$this->patient->id.'/events', [
                'title' => 'موعد',
                'starts_at' => '2026-11-06 00:00:00',
            ])
            ->assertStatus(201)
            ->json('data');

        $this->assertSame('Africa/Cairo', $event['timezone']);
        $this->assertSame('2026-11-05T22:00:00+00:00', $event['starts_at']);
        $this->assertInstanceOf(CalendarEvent::class, CalendarEvent::find($event['id']));
    }
}
