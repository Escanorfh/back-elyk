<?php

namespace Tests\Feature\Calendar;

use App\Models\CalendarEvent;
use App\Models\PersonalTask;
use App\Models\Reminder;
use App\Models\User;
use App\Models\UserSettings;
use App\Services\Calendar\PersonalTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * واجهات المريض للتقويم (§4): الوصول، الملكية، والعزل بين المرضى.
 * القواعد نفسها تُختبر في CalendarServicesTest — هنا إثبات أن الواجهة تمررها ولا تخترع بديلًا.
 */
class PatientCalendarApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $patient;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->patient = User::factory()->create(['role' => 'patient']);
        $this->other = User::factory()->create(['role' => 'patient']);

        UserSettings::create(['user_id' => $this->patient->id, 'timezone' => 'Africa/Cairo']);

        Notification::fake();
    }

    /** @param array<string, mixed> $extra */
    private function eventPayload(array $extra = []): array
    {
        return array_merge([
            'title' => 'موعد عيادة خاصة',
            'starts_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ], $extra);
    }

    /** @param array<string, mixed> $extra */
    private function taskPayload(array $extra = []): array
    {
        return array_merge([
            'title' => 'تمرين تنفس',
            'due_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ], $extra);
    }

    public function test_the_patient_calendar_aggregates_own_rows_only()
    {
        $this->actingAs($this->patient)->postJson('/api/v1/me/events', $this->eventPayload())->assertStatus(201);
        $this->actingAs($this->patient)->postJson('/api/v1/me/tasks', $this->taskPayload())->assertStatus(201);
        // عنصر لمريض آخر لا يظهر أبدًا في تقويمي.
        $this->actingAs($this->other)->postJson('/api/v1/me/events', $this->eventPayload(['title' => 'خصوصي']))->assertStatus(201);

        $response = $this->actingAs($this->patient)
            ->getJson('/api/v1/me/calendar?view=month')
            ->assertStatus(200)
            ->assertJsonPath('data.range.timezone', 'Africa/Cairo');

        $titles = collect($response->json('data.items'))->pluck('title')->all();
        // الترتيب بالوقت: مهمة اليوم التالي تسبق حدث اليوم الثاني.
        $this->assertSame(['تمرين تنفس', 'موعد عيادة خاصة'], $titles);
        $this->assertSame(1, $response->json('data.counts.calendar_event'));
        $this->assertSame(1, $response->json('data.counts.personal_task'));
        $this->assertSame(0, $response->json('data.counts.live_session'), 'كل مصدر مطلوب يظهر ولو بصفر');
    }

    public function test_an_explicit_timezone_param_rebuilds_the_window_from_that_zone()
    {
        // التاريخ واحد والمنطقة مختلفة: نافذة اليوم تبدأ من منتصف ليلها المحلي لا من منتصف ليل UTC.
        $cairo = $this->actingAs($this->patient)
            ->getJson('/api/v1/me/calendar?view=day&date=2026-11-05&timezone=Africa/Cairo')
            ->assertStatus(200)
            ->json('data');
        $tokyo = $this->actingAs($this->patient)
            ->getJson('/api/v1/me/calendar?view=day&date=2026-11-05&timezone=Asia/Tokyo')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('Africa/Cairo', $cairo['range']['timezone']);
        $this->assertSame('Asia/Tokyo', $tokyo['range']['timezone'], 'الطلب الصريح يسبق منطقة المريض المعلَنة');
        $this->assertSame('2026-11-04T22:00:00+00:00', $cairo['range']['from']);
        $this->assertSame('2026-11-04T15:00:00+00:00', $tokyo['range']['from']);
    }

    public function test_an_admin_cannot_use_the_patient_calendar_endpoints()
    {
        $this->actingAs($this->admin)->getJson('/api/v1/me/calendar')->assertStatus(403);
        $this->actingAs($this->admin)->postJson('/api/v1/me/tasks', $this->taskPayload())->assertStatus(403);
        $this->actingAs($this->admin)->getJson('/api/v1/me/events')->assertStatus(403);
    }

    public function test_a_guest_is_redirected_to_login_by_the_api_guard()
    {
        $this->getJson('/api/v1/me/calendar')->assertStatus(401);
    }

    public function test_an_event_is_created_listed_updated_and_deleted()
    {
        $created = $this->actingAs($this->patient)
            ->postJson('/api/v1/me/events', $this->eventPayload([
                'type' => 'other_clinical_appointment',
                'reminder_offsets' => [30, 1440],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'other_clinical_appointment')
            ->assertJsonPath('data.editable_by_user', true);

        $id = $created->json('data.id');
        $this->assertDatabaseHas('calendar_events', ['id' => $id, 'user_id' => $this->patient->id, 'created_by' => null]);
        $this->assertSame(2, Reminder::query()->where('notifiable_type', CalendarEvent::class)->where('notifiable_id', $id)->count());

        $this->actingAs($this->patient)->getJson("/api/v1/me/events")->assertStatus(200)->assertJsonCount(1, 'data');

        $this->actingAs($this->patient)
            ->putJson("/api/v1/me/events/{$id}", ['title' => 'بعد التأجيل', 'reminder_offsets' => [10]])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'بعد التأجيل');
        // الإزاحات الجديدة تستبدل القديمة: لا تذكير يتيم لوقت قديم.
        $this->assertSame(1, Reminder::query()->where('notifiable_type', CalendarEvent::class)->where('notifiable_id', $id)->where('status', 'pending')->count());

        $this->actingAs($this->patient)->deleteJson("/api/v1/me/events/{$id}")->assertStatus(200);
        $this->assertDatabaseMissing('calendar_events', ['id' => $id]);
        $this->assertSame(0, Reminder::query()->where('notifiable_type', CalendarEvent::class)->where('notifiable_id', $id)->where('status', 'pending')->count());
    }

    public function test_one_patient_cannot_read_or_write_anothers_rows()
    {
        $id = $this->actingAs($this->patient)->postJson('/api/v1/me/events', $this->eventPayload())->json('data.id');
        $taskId = $this->actingAs($this->patient)->postJson('/api/v1/me/tasks', $this->taskPayload())->json('data.id');

        $this->actingAs($this->other)->getJson("/api/v1/me/events/{$id}")->assertStatus(403);
        $this->actingAs($this->other)->putJson("/api/v1/me/events/{$id}", ['title' => 'اختراق'])->assertStatus(403);
        $this->actingAs($this->other)->deleteJson("/api/v1/me/events/{$id}")->assertStatus(403);
        $this->actingAs($this->other)->postJson("/api/v1/me/tasks/{$taskId}/complete")->assertStatus(403);

        $this->assertDatabaseHas('calendar_events', ['id' => $id, 'title' => 'موعد عيادة خاصة']);
    }

    public function test_a_therapist_assigned_task_is_readable_but_not_editable_by_the_patient()
    {
        $task = app(PersonalTaskService::class)->create($this->patient, $this->taskPayload([
            'category' => 'therapeutic_homework',
        ]), $this->admin);

        $show = $this->actingAs($this->patient)->getJson("/api/v1/me/tasks/{$task->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.editable_by_user', false);
        $this->assertNotNull($show->json('data.id'));

        // النص ملك المعالج، لكن إتمام المهمة قرار المريض.
        $this->actingAs($this->patient)->putJson("/api/v1/me/tasks/{$task->id}", ['title' => 'تغيير'])->assertStatus(403);
        $this->actingAs($this->patient)->deleteJson("/api/v1/me/tasks/{$task->id}")->assertStatus(403);
        $this->actingAs($this->patient)->postJson("/api/v1/me/tasks/{$task->id}/complete")->assertStatus(200);
    }

    public function test_complete_and_reopen_move_a_task_and_stamp_completed_at()
    {
        $id = $this->actingAs($this->patient)->postJson('/api/v1/me/tasks', $this->taskPayload())->json('data.id');

        $completed = $this->actingAs($this->patient)->postJson("/api/v1/me/tasks/{$id}/complete")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');
        $this->assertNotNull($completed->json('data.completed_at'));

        $reopened = $this->actingAs($this->patient)->postJson("/api/v1/me/tasks/{$id}/reopen")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'pending');
        $this->assertNull($reopened->json('data.completed_at'));
    }

    public function test_complete_and_reopen_reject_states_that_do_not_fit()
    {
        $id = $this->actingAs($this->patient)->postJson('/api/v1/me/tasks', $this->taskPayload())->json('data.id');

        // إعادة فتح مهمة مفتوحة أصلًا خطأ، والإعادة لا تبدأ من pending.
        $this->actingAs($this->patient)->postJson("/api/v1/me/tasks/{$id}/reopen")->assertStatus(422);

        $this->actingAs($this->patient)->putJson("/api/v1/me/tasks/{$id}", ['status' => 'cancelled'])->assertStatus(200);
        $this->actingAs($this->patient)->postJson("/api/v1/me/tasks/{$id}/complete")->assertStatus(422);
    }

    public function test_the_list_endpoints_filter_by_status()
    {
        $a = $this->actingAs($this->patient)->postJson('/api/v1/me/tasks', $this->taskPayload(['title' => 'أ']))->json('data.id');
        $this->actingAs($this->patient)->postJson('/api/v1/me/tasks', $this->taskPayload(['title' => 'ب']))->assertStatus(201);
        $this->actingAs($this->patient)->postJson("/api/v1/me/tasks/{$a}/complete")->assertStatus(200);

        $this->actingAs($this->patient)->getJson('/api/v1/me/tasks?status=pending')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'ب');
        $this->actingAs($this->patient)->getJson('/api/v1/me/tasks?status=completed')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'أ');
        // حالة ليست في القانون تُرفض بدل أن تُرجع قائمة فارغة بلا سبب.
        $this->actingAs($this->patient)->getJson('/api/v1/me/tasks?status=teleported')->assertStatus(422);
    }

    public function test_the_api_rejects_input_that_the_service_law_does_not_allow()
    {
        $this->actingAs($this->patient)->postJson('/api/v1/me/events', ['title' => 'بلا وقت'])->assertStatus(422);
        $this->actingAs($this->patient)->postJson('/api/v1/me/events', ['starts_at' => now()->toDateTimeString()])->assertStatus(422);
        $this->actingAs($this->patient)
            ->postJson('/api/v1/me/events', $this->eventPayload([
                'ends_at' => now()->subDay()->format('Y-m-d H:i:s'),
            ]))
            ->assertStatus(422);
        $this->actingAs($this->patient)
            ->postJson('/api/v1/me/events', $this->eventPayload(['timezone' => 'Mars/Olympus_Mons']))
            ->assertStatus(422);
        $this->actingAs($this->patient)
            ->postJson('/api/v1/me/tasks', $this->taskPayload(['category' => 'clinical_chart']))
            ->assertStatus(422);

        $this->assertSame(0, CalendarEvent::query()->count());
        $this->assertSame(0, PersonalTask::query()->count());
    }

    public function test_the_calendar_range_and_source_filters_are_honoured()
    {
        $this->actingAs($this->patient)->postJson('/api/v1/me/tasks', $this->taskPayload())->assertStatus(201);

        // نطاق خارج المتاح: لا عناصر، لكن المصادر الأربعة تبقى في counts.
        $far = $this->actingAs($this->patient)
            ->getJson('/api/v1/me/calendar?from=2030-01-01&to=2030-01-02')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.items')
            ->json('data.counts');
        $this->assertSame(['live_session' => 0, 'path_step' => 0, 'personal_task' => 0, 'calendar_event' => 0], $far);

        $onlyTasks = $this->actingAs($this->patient)
            ->getJson('/api/v1/me/calendar?view=month&types[]=personal_task')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.items')
            ->json('data.counts');
        $this->assertSame(['personal_task' => 1], $onlyTasks);

        $this->actingAs($this->patient)->getJson('/api/v1/me/calendar?types[]=ghost')->assertStatus(422);
        $this->actingAs($this->patient)->getJson('/api/v1/me/calendar?timezone=Mars/Olympus_Mons')->assertStatus(422);
        $this->actingAs($this->patient)->getJson('/api/v1/me/calendar?from=2030-01-05&to=2030-01-01')->assertStatus(422);
    }

    /**
     * §6.4 القراءة الراجعة: نموذج التحرير يجب أن يرى كل ما هو مجدول فعلًا، وإلا ظهر فارغًا وحفظٌ
     * لم يلمسه أحد مسح التذكيرات. الحقل المفرد القديم يبقى أبكر لحظة تذكير فلا تنكسر شبكة Phase 5.
     */
    public function test_the_read_models_report_every_pending_offset_so_editing_cannot_wipe_them()
    {
        $this->actingAs($this->patient)
            ->postJson('/api/v1/me/events', $this->eventPayload(['reminder_offsets' => [1440, 60, 0]]))
            ->assertStatus(201);
        $this->actingAs($this->patient)
            ->postJson('/api/v1/me/tasks', $this->taskPayload(['reminder_offsets' => [1440, 60, 0]]))
            ->assertStatus(201);

        $event = collect($this->actingAs($this->patient)->getJson('/api/v1/me/calendar?view=month')->json('data.items'))
            ->firstWhere('source', 'calendar_event');

        $this->assertSame([0, 60, 1440], $event['reminder_offsets']);
        $this->assertSame(1440, $event['reminder']['offset_minutes'], 'المفرد هو أبكر تذكير: أكبر إزاحة تُجدَّل قبل الموعد');

        $this->actingAs($this->patient)
            ->getJson('/api/v1/me/tasks')
            ->assertStatus(200)
            ->assertJsonPath('data.0.reminder_offsets', [0, 60, 1440]);
    }
}
