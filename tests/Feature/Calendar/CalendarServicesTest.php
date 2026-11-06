<?php

namespace Tests\Feature\Calendar;

use App\Models\AppSetting;
use App\Models\CalendarEvent;
use App\Models\Disorder;
use App\Models\LiveSession;
use App\Models\PathStep;
use App\Models\PatientPath;
use App\Models\PersonalTask;
use App\Models\Reminder;
use App\Models\User;
use App\Models\UserSettings;
use App\Notifications\LiveCancelledNotification;
use App\Notifications\LiveRescheduledNotification;
use App\Notifications\LiveScheduledNotification;
use App\Services\Calendar\CalendarAggregationService;
use App\Services\Calendar\ConflictDetectionService;
use App\Services\Calendar\PathStepScheduleService;
use App\Services\Calendar\PersonalEventService;
use App\Services\Calendar\PersonalTaskService;
use App\Services\Calendar\ReminderSyncService;
use App\Services\Calendar\SessionScheduleService;
use App\Support\CalendarTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * خدمات التقويم: التجميع، التعارض، مزامينة التذكيرات، ودورة حياة الجلسة. كل قاعدة زمن أو ملكية
 * تُختبر هنا تُطبَّق في خدمة واحدة، فلا يقرر endpoint السلوك بنفسه.
 */
class CalendarServicesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->patient = User::factory()->create(['role' => 'patient']);

        UserSettings::create(['user_id' => $this->patient->id, 'timezone' => 'Africa/Cairo']);

        Notification::fake();
    }

    private function services(): array
    {
        $sync = new ReminderSyncService;
        $conflicts = new ConflictDetectionService;

        return [
            'sync' => $sync,
            'conflicts' => $conflicts,
            'events' => new PersonalEventService($sync),
            'tasks' => new PersonalTaskService($sync),
            'steps' => new PathStepScheduleService($sync),
            'sessions' => new SessionScheduleService($sync, $conflicts),
            'aggregate' => new CalendarAggregationService,
        ];
    }

    private function enableWidening(): void
    {
        AppSetting::setValue('calendar.status_widening_enabled', true, 'boolean', 'calendar');
    }

    /**
     * الخدمات تُرفض عبر abort(): داخل طلب HTTP يحوَّل الاستثناء إلى response، أما في اختبار
     * خدمة مباشرة فيصل كما هو — الحالتان هنا (HttpException و HttpResponseException).
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function abortCode(callable $call): array
    {
        try {
            $call();
        } catch (HttpResponseException $e) {
            $response = $e->getResponse();

            return [$response->getStatusCode(), json_decode($response->getContent(), true) ?: []];
        } catch (HttpException $e) {
            return [$e->getStatusCode(), ['message' => $e->getMessage()]];
        }

        throw new AssertionFailedError('the call was expected to abort');
    }

    private function rawSession(int $adminId, ?int $patientId, string $start, ?string $end = null, string $status = 'scheduled'): LiveSession
    {
        return LiveSession::create([
            'admin_id' => $adminId,
            'patient_id' => $patientId,
            'title' => 'جلسة '.$start,
            'status' => $status,
            'scheduled_at' => $start,
            'ends_at' => $end,
            // livekit_room_name فريد في المخطط: لا صفّان بغرفة واحدة.
            'livekit_room_name' => 'room_'.Str::random(10),
        ]);
    }

    private function pathFor(User $patient): PatientPath
    {
        $disorder = Disorder::create(['name' => 'D1', 'name_ar' => 'D1']);

        return PatientPath::create(['patient_id' => $patient->id, 'disorder_id' => $disorder->id, 'status' => 'active']);
    }

    // ── التجميع ────────────────────────────────────────────────────────────────

    public function test_patient_calendar_aggregates_four_sources_and_hides_other_patients()
    {
        $services = $this->services();
        $other = User::factory()->create(['role' => 'patient']);

        $this->rawSession($this->admin->id, $this->patient->id, '2026-11-05 14:00:00');
        $this->rawSession($this->admin->id, $other->id, '2026-11-05 14:00:00');

        CalendarEvent::create(['user_id' => $this->patient->id, 'title' => 'E', 'starts_at' => '2026-11-06 09:00:00']);
        CalendarEvent::create(['user_id' => $other->id, 'title' => 'E-other', 'starts_at' => '2026-11-06 09:00:00']);

        PersonalTask::create(['user_id' => $this->patient->id, 'title' => 'T', 'due_at' => '2026-11-07 08:00:00']);
        PersonalTask::create(['user_id' => $other->id, 'title' => 'T-other', 'due_at' => '2026-11-07 08:00:00']);

        PathStep::create([
            'patient_path_id' => $this->pathFor($this->patient)->id, 'order' => 1,
            'title' => 'Step', 'status' => 'active', 'due_at' => '2026-11-08 06:00:00',
        ]);
        PathStep::create([
            'patient_path_id' => $this->pathFor($other)->id, 'order' => 1,
            'title' => 'Step-other', 'status' => 'active', 'due_at' => '2026-11-08 06:00:00',
        ]);

        $calendar = $services['aggregate']->forPatient($this->patient, [
            'from' => '2026-11-04 00:00:00',
            'to' => '2026-11-09 00:00:00',
        ]);

        $this->assertSame(
            ['live_session', 'calendar_event', 'personal_task', 'path_step'],
            array_column($calendar['items'], 'source'),
            'one row per source, ordered by instant'
        );

        $this->assertNotContains('E-other', array_column($calendar['items'], 'title'));
        $this->assertNotContains('T-other', array_column($calendar['items'], 'title'));
        $this->assertNotContains('Step-other', array_column($calendar['items'], 'title'));

        $this->assertSame(1, $calendar['counts']['live_session']);
        $this->assertSame(1, $calendar['counts']['calendar_event']);
        $this->assertSame(1, $calendar['counts']['personal_task']);
        $this->assertSame(1, $calendar['counts']['path_step']);

        $this->assertFalse($calendar['truncated']);
        // منتصف الليل المعلن بلغة المريض، لا بلغة الخادم: القاهرة +٢ → ٢٢:٠٠ من اليوم السابق.
        $this->assertSame('2026-11-03T22:00:00+00:00', $calendar['range']['from']);
        $this->assertSame('2026-11-08T22:00:00+00:00', $calendar['range']['to']);
        $this->assertSame('Africa/Cairo', $calendar['range']['timezone'], 'the declared zone drives the range');
    }

    public function test_bucket_and_overdue_are_computed_not_stored()
    {
        $services = $this->services();
        $now = CarbonImmutable::now('UTC');

        PersonalTask::create(['user_id' => $this->patient->id, 'title' => 'late', 'due_at' => $now->copy()->subDay(), 'status' => 'pending']);
        PersonalTask::create(['user_id' => $this->patient->id, 'title' => 'done', 'due_at' => $now->copy()->subDays(3), 'status' => 'completed']);
        PersonalTask::create(['user_id' => $this->patient->id, 'title' => 'undated']);

        $calendar = $services['aggregate']->forPatient($this->patient, [
            'from' => $now->copy()->subMonth()->format('Y-m-d H:i:s'),
            'to' => $now->copy()->addMonth()->format('Y-m-d H:i:s'),
        ]);

        $items = $calendar['items'];

        $this->assertSame(['done', 'late'], array_column($items, 'title'), 'the undated row is a separate bucket, not a window member');
        $this->assertSame(['completed', 'overdue'], array_column($items, 'bucket'));
        $this->assertSame([false, true], array_column($items, 'overdue'), 'only the open past-due row is overdue');
        $this->assertTrue($items[0]['editable_by_user'], 'both rows are the patient\'s own tasks');

        $this->assertSame('no_date', CalendarTime::bucket(null, 'pending', $now, 'Africa/Cairo'));
    }

    public function test_aggregation_rejects_unknown_source_bad_timezone_and_inverted_range()
    {
        $services = $this->services();

        foreach ([
            ['types' => 'no_such_source'],
            ['timezone' => 'Cairo'],
            ['from' => '2026-11-09 00:00:00', 'to' => '2026-11-05 00:00:00'],
        ] as $index => $filters) {
            $this->assertSame(
                422,
                $this->abortCode(fn () => $services['aggregate']->forPatient($this->patient, $filters))[0],
                "filter #{$index} must abort"
            );
        }
    }

    public function test_types_filter_narrows_the_sources()
    {
        $services = $this->services();

        PersonalTask::create(['user_id' => $this->patient->id, 'title' => 'T', 'due_at' => '2026-11-06 08:00:00']);
        CalendarEvent::create(['user_id' => $this->patient->id, 'title' => 'E', 'starts_at' => '2026-11-06 09:00:00']);

        $onlyTasks = $services['aggregate']->forPatient($this->patient, [
            'from' => '2026-11-05 00:00:00',
            'to' => '2026-11-07 00:00:00',
            'types' => 'personal_task',
        ]);

        $this->assertSame(['personal_task'], array_column($onlyTasks['items'], 'source'));
        $this->assertSame(['personal_task' => 1], array_map('intval', $onlyTasks['counts']));
    }

    public function test_range_cap_truncates_the_items_but_counts_every_match()
    {
        AppSetting::setValue('calendar.range_max_items', 2, 'integer', 'calendar');

        foreach ([1, 2, 3] as $day) {
            CalendarEvent::create([
                'user_id' => $this->patient->id, 'title' => "D{$day}", 'starts_at' => "2026-11-0{$day} 10:00:00",
            ]);
        }

        $calendar = $this->services()['aggregate']->forPatient($this->patient, [
            'from' => '2026-11-01 00:00:00',
            'to' => '2026-11-30 00:00:00',
        ]);

        $this->assertCount(2, $calendar['items']);
        $this->assertTrue($calendar['truncated']);
        $this->assertSame(3, $calendar['counts']['calendar_event']);
    }

    public function test_admin_calendar_sees_every_patient_until_one_is_named()
    {
        $services = $this->services();
        $other = User::factory()->create(['role' => 'patient']);

        PersonalTask::create(['user_id' => $this->patient->id, 'title' => 'mine', 'due_at' => '2026-11-06 08:00:00']);
        PersonalTask::create(['user_id' => $other->id, 'title' => 'theirs', 'due_at' => '2026-11-06 08:00:00']);
        $this->rawSession($this->admin->id, $other->id, '2026-11-06 12:00:00');
        $this->rawSession(User::factory()->create(['role' => 'admin'])->id, $other->id, '2026-11-06 13:00:00');

        $range = ['from' => '2026-11-05 00:00:00', 'to' => '2026-11-07 00:00:00'];

        $everything = $services['aggregate']->forAdmin($this->admin, $range);

        $this->assertSame(['mine', 'theirs'], array_values(array_filter(
            array_column($everything['items'], 'title'),
            fn (string $t): bool => in_array($t, ['mine', 'theirs'], true)
        )), 'staff see both patients\' tasks');
        $this->assertSame(1, $everything['counts']['live_session'], 'only this admin\'s sessions');
        $this->assertTrue($everything['items'][0]['editable_by_user']);

        $onePatient = $services['aggregate']->forAdmin($this->admin, $range + ['patient_id' => $this->patient->id]);

        $this->assertSame(['mine'], array_values(array_filter(
            array_column($onePatient['items'], 'title'),
            fn (string $t): bool => in_array($t, ['mine', 'theirs'], true)
        )));
        $this->assertSame(0, $onePatient['counts']['live_session'], 'the named patient has no session here');
    }

    // ── التعارض والتوافر ───────────────────────────────────────────────────────

    public function test_conflicts_ignore_cancelled_and_missed_sessions()
    {
        $services = $this->services();
        $at = CarbonImmutable::parse('2026-11-05 14:30:00');

        $this->rawSession($this->admin->id, $this->patient->id, '2026-11-05 14:00:00', '2026-11-05 15:00:00', 'cancelled');
        $this->rawSession($this->admin->id, $this->patient->id, '2026-11-05 14:00:00', '2026-11-05 15:00:00', 'missed');

        $this->assertTrue(
            $services['conflicts']->sessionConflictsForPatients([$this->patient->id], $at)->isEmpty(),
            'a slot freed by cancellation is bookable again'
        );

        $live = $this->rawSession($this->admin->id, $this->patient->id, '2026-11-05 14:00:00', '2026-11-05 15:00:00');

        $this->assertSame(
            [$live->id],
            $services['conflicts']->sessionConflictsForPatients([$this->patient->id], $at)->modelKeys()
        );

        $this->assertTrue(
            $services['conflicts']->sessionConflictsForPatients(
                [$this->patient->id],
                CarbonImmutable::parse('2026-11-05 13:00:00'),
                CarbonImmutable::parse('2026-11-05 14:00:00')
            )->isEmpty(),
            'half-open: ending exactly at the new start is not a clash'
        );
    }

    public function test_availability_lists_busy_items_and_the_gaps_between_the_spanning_ones()
    {
        $services = $this->services();

        $this->rawSession($this->admin->id, $this->patient->id, '2026-11-05 14:00:00', '2026-11-05 15:00:00');
        PersonalTask::create(['user_id' => $this->patient->id, 'title' => 'T', 'due_at' => '2026-11-05 17:00:00']);
        CalendarEvent::create(['user_id' => $this->patient->id, 'title' => 'all-day', 'starts_at' => '2026-11-05 00:00:00', 'all_day' => true]);

        $window = $services['conflicts']->availability(
            $this->patient->id,
            CarbonImmutable::parse('2026-11-05 12:00:00'),
            CarbonImmutable::parse('2026-11-05 20:00:00')
        );

        $this->assertSame(['live_session', 'personal_task'], array_column($window['busy'], 'source'), 'an all-day event never fills the schedule');

        $this->assertSame([
            ['starts_at' => '2026-11-05T12:00:00+00:00', 'ends_at' => '2026-11-05T14:00:00+00:00'],
            ['starts_at' => '2026-11-05T15:00:00+00:00', 'ends_at' => '2026-11-05T20:00:00+00:00'],
        ], $window['free'], 'an instant is shown as busy but does not split a bookable gap');
    }

    // ── مزامينة التذكيرات ──────────────────────────────────────────────────────

    public function test_reminder_sync_retires_dropped_offsets_and_revives_a_moved_cycle()
    {
        $services = $this->services();

        $task = $services['tasks']->create($this->patient, [
            'title' => 'T', 'due_at' => '2026-11-06 18:00:00', 'timezone' => 'UTC', 'reminder_offsets' => [60, 1440],
        ], $this->patient);

        $this->assertSame([60, 1440], $task->reminders()->orderBy('offset_minutes')->pluck('offset_minutes')->all());
        $this->assertSame(2, $task->reminders()->where('audience', 'owner')->count());

        $moved = $services['tasks']->update($task, ['due_at' => '2026-11-06 18:00:00', 'reminder_offsets' => [30]], $this->patient);

        $this->assertSame(
            ['30' => 'pending', '60' => 'cancelled', '1440' => 'cancelled'],
            $moved->reminders()->orderBy('offset_minutes')->pluck('status', 'offset_minutes')->all()
        );

        // دورة جديدة = لحظة جديدة؛ طلب إزاحة قديمة يُحيي صفها بدل الاصطدام بالقيد الفريد.
        $again = $services['tasks']->update($moved, ['due_at' => '2026-11-08 18:00:00', 'reminder_offsets' => [60]], $this->patient);

        $this->assertSame(1, $again->reminders()->where('status', 'pending')->count());
        $this->assertSame(
            '2026-11-08 17:00:00',
            $again->reminders()->where('status', 'pending')->first()->reminds_at->format('Y-m-d H:i:s')
        );
        $this->assertSame(2, $again->reminders()->where('offset_minutes', 60)->count(), 'the old cycle stays as history');
    }

    public function test_sync_without_offsets_hands_off_and_sent_rows_are_untouchable()
    {
        $sync = new ReminderSyncService;
        $task = PersonalTask::create(['user_id' => $this->patient->id, 'title' => 'T', 'due_at' => '2026-11-06 18:00:00']);
        $base = CarbonImmutable::parse('2026-11-06 18:00:00');

        $sync->sync($task, [60], $base, 'UTC');

        $sent = Reminder::create([
            'notifiable_type' => $task->getMorphClass(), 'notifiable_id' => $task->id,
            'audience' => 'owner', 'offset_minutes' => 1440,
            'reminds_at' => '2026-11-05 18:00:00', 'status' => 'sent', 'sent_at' => '2026-11-05 18:00:00',
        ]);

        // null = لا مساس بالجدولة القائمة (تحديث عنوان لا يعيد كتابة المواعيد).
        $sync->sync($task, null, $base, 'UTC');
        $this->assertSame([60], $task->reminders()->where('status', 'pending')->pluck('offset_minutes')->all());

        $sync->sync($task, [30], $base, 'UTC');

        $this->assertSame('sent', $sent->fresh()->status, 'delivered is a fact, not a schedule');
        $this->assertSame('cancelled', Reminder::where(['notifiable_id' => $task->id, 'offset_minutes' => 60])->value('status'));
        $this->assertSame([30], $task->reminders()->where('status', 'pending')->pluck('offset_minutes')->all());
    }

    // ── مهام المريض ────────────────────────────────────────────────────────────

    public function test_completion_fills_the_stamp_and_reopening_clears_it()
    {
        $services = $this->services();

        $task = $services['tasks']->create($this->patient, ['title' => 'T'], $this->patient);
        $this->assertNull($task->created_by, 'a self-created task carries no attribution');

        $done = $services['tasks']->complete($task);
        $this->assertSame('completed', $done->status);
        $this->assertNotNull($done->completed_at);

        $open = $services['tasks']->reopen($done);
        $this->assertSame('pending', $open->status);
        $this->assertNull($open->completed_at);

        $assigned = $services['tasks']->create($this->patient, ['title' => 'A'], $this->admin);
        $this->assertSame($this->admin->id, $assigned->created_by);
        $this->assertFalse($assigned->isEditableBy($this->patient->id), 'content the therapist set stays read-only for the patient');
        $this->assertFalse($assigned->isEditableBy($this->admin->id), 'isEditableBy is the patient-side rule; staff edit through the admin gate');
    }

    public function test_illegal_task_moves_abort()
    {
        $services = $this->services();

        $cancelled = $services['tasks']->create($this->patient, ['title' => 'C', 'status' => 'cancelled'], $this->patient);
        $pending = $services['tasks']->create($this->patient, ['title' => 'P'], $this->patient);

        $this->assertSame(422, $this->abortCode(fn () => $services['tasks']->complete($cancelled))[0], 'a cancelled task is not ticked off');
        $this->assertSame(422, $this->abortCode(fn () => $services['tasks']->reopen($pending))[0], 'an open task is not reopened');

        // الإلغاء ثم إعادة الفتح مسار مشروع: الحالة تعود pending ويُ clears completed_at.
        $reopened = $services['tasks']->reopen($services['tasks']->complete($pending));
        $this->assertSame('pending', $reopened->status);
    }

    public function test_deleting_a_task_cancels_its_pending_reminders()
    {
        $services = $this->services();

        $task = $services['tasks']->create($this->patient, [
            'title' => 'T', 'due_at' => '2026-11-06 18:00:00', 'reminder_offsets' => [15],
        ], $this->patient);

        $services['tasks']->delete($task);

        $this->assertSame('cancelled', Reminder::where('notifiable_id', $task->id)->value('status'));
    }

    // ── أحداث المريض ───────────────────────────────────────────────────────────

    public function test_the_explicit_offset_wins_and_an_untouched_side_keeps_its_instant()
    {
        $services = $this->services();

        $event = $services['events']->create($this->patient, [
            'title' => 'E',
            'starts_at' => '2026-11-05T18:00:00+03:00',
            'ends_at' => '2026-11-05T19:00:00+03:00',
            'timezone' => 'Africa/Nairobi',
        ], $this->patient);

        $this->assertSame('2026-11-05 15:00:00', $event->starts_at->format('Y-m-d H:i:s'), 'the supplied offset beats the item zone');
        $this->assertSame('2026-11-05 16:00:00', $event->ends_at->format('Y-m-d H:i:s'));

        $moved = $services['events']->update($event, ['ends_at' => '2026-11-05 21:00:00']);

        $this->assertSame('2026-11-05 15:00:00', $moved->starts_at->format('Y-m-d H:i:s'), 'moving one side must not shift the other');
        $this->assertSame('2026-11-05 18:00:00', $moved->ends_at->format('Y-m-d H:i:s'), 'the naive end is read in the item zone');
    }

    public function test_an_event_ending_before_it_starts_is_rejected()
    {
        $services = $this->services();

        $this->assertSame(422, $this->abortCode(fn () => $services['events']->create($this->patient, [
            'title' => 'E', 'starts_at' => '2026-11-05 18:00:00', 'ends_at' => '2026-11-05 17:00:00',
        ], $this->patient))[0]);
    }

    // ── خطوات المسار ───────────────────────────────────────────────────────────

    public function test_scheduling_a_step_stores_utc_and_attributes_the_author()
    {
        $services = $this->services();

        $step = PathStep::create([
            'patient_path_id' => $this->pathFor($this->patient)->id,
            'order' => 1, 'title' => 'تمارين التنفس', 'status' => 'active',
        ]);

        $scheduled = $services['steps']->schedule($step, [
            'due_at' => '2026-11-10 20:00:00',
            'timezone' => 'Africa/Cairo',
            'priority' => 'high',
            'category' => 'therapeutic_homework',
            'reminder_offsets' => [120],
        ], $this->admin);

        $this->assertSame('2026-11-10 18:00:00', $scheduled->due_at->format('Y-m-d H:i:s'), 'Cairo is UTC+2 in November');
        $this->assertSame('Africa/Cairo', $scheduled->due_timezone);
        $this->assertSame($this->admin->id, $scheduled->assigned_by);
        $this->assertSame('active', $scheduled->status, 'scheduling never touches the clinical status');
        $this->assertSame('high', $scheduled->priority);

        $reminder = $scheduled->reminders()->first();
        $this->assertSame(120, $reminder->offset_minutes);
        $this->assertSame('owner', $reminder->audience, 'the patient is reminded, not the therapist');
        $this->assertSame('2026-11-10 16:00:00', $reminder->reminds_at->format('Y-m-d H:i:s'));

        $cleared = $services['steps']->clearDate($scheduled);

        $this->assertNull($cleared->due_at);
        $this->assertSame('cancelled', $cleared->reminders()->first()->status);
    }

    public function test_a_closed_step_has_no_future_date_to_schedule()
    {
        $services = $this->services();

        $step = PathStep::create([
            'patient_path_id' => $this->pathFor($this->patient)->id,
            'order' => 1, 'title' => 'S', 'status' => 'submitted', 'submitted_at' => now(),
        ]);

        $this->assertSame(422, $this->abortCode(fn () => $services['steps']->schedule($step, ['due_at' => '2026-11-10 20:00:00'], $this->admin))[0]);
    }

    // ── دورة حياة الجلسة ───────────────────────────────────────────────────────

    public function test_creating_a_session_notifies_the_invitees_and_arms_participant_reminders()
    {
        $services = $this->services();

        $session = $services['sessions']->create($this->admin, [
            'title' => 'جلسة متابعة',
            'scheduled_at' => '2026-11-06 19:00:00',
            'ends_at' => '2026-11-06 20:00:00',
            'timezone' => 'Africa/Cairo',
            'patient_ids' => [$this->patient->id],
            'reminder_offsets' => [30],
        ]);

        $this->assertSame('scheduled', $session->status);
        $this->assertSame('2026-11-06 17:00:00', $session->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('Africa/Cairo', $session->timezone);
        $this->assertSame([$this->patient->id], $session->invitedPatients()->pluck('users.id')->all());
        $this->assertSame('participants', $session->reminders()->first()->audience);

        Notification::assertSentTo($this->patient, LiveScheduledNotification::class);
    }

    public function test_an_invited_user_that_is_not_a_patient_is_rejected()
    {
        $services = $this->services();

        $this->assertSame(422, $this->abortCode(fn () => $services['sessions']->create($this->admin, [
            'title' => 'X', 'scheduled_at' => '2026-11-06 19:00:00', 'patient_ids' => [$this->admin->id],
        ]))[0]);
    }

    public function test_the_gate_holds_confirm_and_cancel_until_it_is_opened()
    {
        $services = $this->services();
        $session = $this->rawSession($this->admin->id, $this->patient->id, '2026-11-06 19:00:00');

        $this->assertSame(409, $this->abortCode(fn () => $services['sessions']->confirm($session))[0]);
        $this->assertSame(409, $this->abortCode(fn () => $services['sessions']->cancel($session, 'ظرف طارئ'))[0]);
        $this->assertSame('scheduled', $session->fresh()->status);

        $this->enableWidening();

        $confirmed = $services['sessions']->confirm($session->fresh());
        $this->assertSame('confirmed', $confirmed->status);
        $this->assertNotNull($confirmed->confirmed_at);

        $cancelled = $services['sessions']->cancel($confirmed, 'ظرف طارئ');

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertStringContainsString('Cancelled: ظرف طارئ', (string) $cancelled->description);

        Notification::assertSentTo($this->patient, LiveCancelledNotification::class);
    }

    public function test_rescheduling_writes_history_and_refuses_to_pull_a_running_stream_back()
    {
        $services = $this->services();
        $this->enableWidening();

        $session = $services['sessions']->create($this->admin, [
            'title' => 'A', 'scheduled_at' => '2026-11-06 19:00:00', 'ends_at' => '2026-11-06 20:00:00',
            'patient_ids' => [$this->patient->id], 'reminder_offsets' => [30],
        ]);

        $rescheduled = $services['sessions']->reschedule($session, '2026-11-09 19:00:00', '2026-11-09 20:00:00', $this->admin);

        $this->assertSame('scheduled', $rescheduled->status);
        $this->assertSame('2026-11-06 19:00:00', $rescheduled->previous_scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-09 19:00:00', $rescheduled->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->admin->id, $rescheduled->rescheduled_by);
        $this->assertNotNull($rescheduled->rescheduled_at);

        // التذكير ينتقل مع الموعد: لا معلَّق على اللحظة القديمة ولا صف مكرر.
        $this->assertSame(['cancelled', 'pending'], $rescheduled->reminders()->orderBy('id')->pluck('status')->all());
        $this->assertSame(
            '2026-11-09 18:30:00',
            $rescheduled->reminders()->where('status', 'pending')->first()->reminds_at->format('Y-m-d H:i:s')
        );

        Notification::assertSentTo($this->patient, LiveRescheduledNotification::class);

        $live = $services['sessions']->markLive($rescheduled);

        $this->assertSame(409, $this->abortCode(fn () => $services['sessions']->reschedule($live, '2026-11-12 19:00:00', null, $this->admin))[0]);
        $this->assertSame('live', $live->fresh()->status);
    }

    public function test_a_cancelled_session_is_terminal_for_every_later_move()
    {
        $services = $this->services();
        $this->enableWidening();

        $session = $this->rawSession($this->admin->id, $this->patient->id, '2026-11-06 19:00:00');
        $cancelled = $services['sessions']->cancel($session, 'اختبار');

        foreach ([
            'end' => fn () => $services['sessions']->markEnded($cancelled),
            'live' => fn () => $services['sessions']->markLive($cancelled),
            'edit' => fn () => $services['sessions']->update($cancelled, ['title' => 'x']),
            'confirm' => fn () => $services['sessions']->confirm($cancelled),
        ] as $label => $call) {
            $this->assertSame(409, $this->abortCode($call)[0], "a cancelled session is not {$label}able");
        }

        $this->assertSame('جلسة 2026-11-06 19:00:00', $cancelled->fresh()->title, 'no write landed on the terminal row');
    }

    public function test_double_booking_a_patient_or_the_therapist_is_refused_with_the_clash()
    {
        $services = $this->services();

        $first = $services['sessions']->create($this->admin, [
            'title' => 'A', 'scheduled_at' => '2026-11-06 19:00:00', 'ends_at' => '2026-11-06 20:00:00',
            'patient_ids' => [$this->patient->id],
        ]);

        // نفس المريض → تعارض مريض + تعارض أديبة.
        [$code, $body] = $this->abortCode(fn () => $services['sessions']->create($this->admin, [
            'title' => 'B', 'scheduled_at' => '2026-11-06 19:30:00', 'patient_ids' => [$this->patient->id],
        ]));

        $this->assertSame(409, $code);
        $this->assertSame([$first->id], array_column($body['conflicts']['patients'], 'id'));
        $this->assertSame([$first->id], array_column($body['conflicts']['therapist'], 'id'));

        // مريض آخر لكن نفس الأديبة: لا تعارض مريض، والتعارض على جدول الأديبة وحده.
        [, $second] = $this->abortCode(fn () => $services['sessions']->create($this->admin, [
            'title' => 'C', 'scheduled_at' => '2026-11-06 19:30:00',
            'patient_ids' => [User::factory()->create(['role' => 'patient'])->id],
        ]));

        $this->assertSame([], $second['conflicts']['patients']);
        $this->assertSame([$first->id], array_column($second['conflicts']['therapist'], 'id'));

        $overridden = $services['sessions']->create($this->admin, [
            'title' => 'D', 'scheduled_at' => '2026-11-06 19:30:00',
            'patient_ids' => [$this->patient->id], 'allow_conflict' => true,
        ]);

        $this->assertSame('scheduled', $overridden->status, 'the therapist may still override knowingly');
    }

    public function test_an_open_session_is_still_bounded_by_the_therapist_calendar()
    {
        $services = $this->services();

        $services['sessions']->create($this->admin, [
            'title' => 'عام', 'scheduled_at' => '2026-11-06 19:00:00', 'ends_at' => '2026-11-06 20:00:00',
        ]);

        [$code, $body] = $this->abortCode(fn () => $services['sessions']->create($this->admin, [
            'title' => 'عام ٢', 'scheduled_at' => '2026-11-06 19:30:00',
        ]));

        $this->assertSame(409, $code);
        $this->assertSame([], $body['conflicts']['patients'], 'an open session has no roster to clash with');
        $this->assertCount(1, $body['conflicts']['therapist']);
    }

    public function test_a_missed_session_reads_as_past_not_overdue()
    {
        $services = $this->services();
        $this->enableWidening();

        $session = $this->rawSession($this->admin->id, $this->patient->id, CarbonImmutable::now('UTC')->subHours(2)->format('Y-m-d H:i:s'));
        $missed = $services['sessions']->markMissed($session);

        $this->assertSame('missed', $missed->status);
        $this->assertNotNull($missed->missed_at);

        $item = collect($services['aggregate']->forPatient($this->patient, [
            'from' => CarbonImmutable::now('UTC')->subDay()->format('Y-m-d H:i:s'),
            'to' => CarbonImmutable::now('UTC')->addDay()->format('Y-m-d H:i:s'),
        ])['items'])->firstWhere('source', 'live_session');

        $this->assertSame('past', $item['bucket']);
        $this->assertFalse($item['overdue']);
    }

    public function test_ending_a_session_through_the_service_cannot_revive_a_terminal_row()
    {
        $services = $this->services();

        $ended = $services['sessions']->markEnded($services['sessions']->markLive(
            $this->rawSession($this->admin->id, $this->patient->id, '2026-11-06 19:00:00')
        ));

        $this->assertSame('ended', $ended->status);
        $this->assertNotNull($ended->ended_at);
        $this->assertSame(409, $this->abortCode(fn () => $services['sessions']->markEnded($ended))[0]);
        $this->assertSame(409, $this->abortCode(fn () => $services['sessions']->markLive($ended))[0]);
    }
}
