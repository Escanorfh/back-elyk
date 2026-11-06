<?php

namespace Tests\Feature\Calendar;

use App\Models\CalendarEvent;
use App\Models\Disorder;
use App\Models\LiveSession;
use App\Models\PathStep;
use App\Models\PatientPath;
use App\Models\PersonalTask;
use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\SchedulerRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * اختبار نماذج التقويم: العلاقات، الصبغات، القيود الفريدة، وقواعد الملكية المشتقة — كلها على
 * مخطط Phase 4.1 تمامًا، بلا أي عمود مضاف هنا.
 */
class CalendarModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_event_casts_and_owner_relation()
    {
        $patient = User::factory()->create(['role' => 'patient']);

        $event = CalendarEvent::create([
            'user_id' => $patient->id,
            'title' => 'يوم مهم',
            'type' => 'important_day',
            'starts_at' => '2026-11-05 18:00:00',
            'ends_at' => '2026-11-05 19:30:00',
            'all_day' => true,
            'timezone' => 'Asia/Dubai',
        ])->fresh();

        $this->assertInstanceOf(Carbon::class, $event->starts_at);
        $this->assertTrue($event->all_day);
        $this->assertSame('scheduled', $event->status, 'defaults come from the schema, not the model');
        $this->assertSame('normal', $event->priority);
        $this->assertSame('important_day', $event->type);
        $this->assertSame($patient->id, $event->user->id);
        $this->assertNull($event->created_by);
    }

    public function test_events_and_tasks_morph_reminders_to_their_owner()
    {
        $patient = User::factory()->create(['role' => 'patient']);
        $event = CalendarEvent::create(['user_id' => $patient->id, 'title' => 'E', 'starts_at' => '2026-11-05 10:00:00']);
        $task = PersonalTask::create(['user_id' => $patient->id, 'title' => 'T']);

        foreach ([$event, $task] as $index => $owner) {
            Reminder::create([
                'notifiable_type' => $owner->getMorphClass(),
                'notifiable_id' => $owner->getKey(),
                'offset_minutes' => [10, 60][$index],
                'reminds_at' => '2026-11-05 09:50:00',
            ]);
        }

        $this->assertCount(1, $event->reminders);
        $this->assertCount(1, $task->fresh()->reminders);
        $this->assertSame('pending', $event->reminders->first()->status);
        $this->assertInstanceOf(CalendarEvent::class, $event->reminders->first()->notifiable);
        $this->assertSame(
            '2026-11-05 09:50:00',
            $task->reminders->first()->reminds_at->setTimezone('UTC')->toDateTimeString()
        );
    }

    public function test_a_task_created_by_a_therapist_is_read_only_for_the_patient()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $patient = User::factory()->create(['role' => 'patient']);
        $other = User::factory()->create(['role' => 'patient']);

        $self = PersonalTask::create(['user_id' => $patient->id, 'title' => 'self']);
        $assigned = PersonalTask::create(['user_id' => $patient->id, 'title' => 'assigned', 'created_by' => $admin->id]);

        $this->assertTrue($self->isEditableBy($patient->id));
        $this->assertFalse($assigned->isEditableBy($patient->id), 'created_by is the only source for editability');
        $this->assertFalse($assigned->isEditableBy($admin->id), 'a non-owner cannot edit even the creator');
        $this->assertFalse($self->isEditableBy($other->id), 'no cross-patient access');
    }

    public function test_range_scopes_are_half_open_so_boundaries_do_not_double_count()
    {
        $patient = User::factory()->create(['role' => 'patient']);
        $from = CarbonImmutable::parse('2026-11-04 00:00:00', 'UTC');
        $to = CarbonImmutable::parse('2026-11-05 00:00:00', 'UTC');

        $startsAtTo = CalendarEvent::create(['user_id' => $patient->id, 'title' => 'x', 'starts_at' => $to, 'ends_at' => $to->addHour()]);
        $endsAtFrom = CalendarEvent::create(['user_id' => $patient->id, 'title' => 'y', 'starts_at' => $from->subHour(), 'ends_at' => $from]);
        $inside = CalendarEvent::create(['user_id' => $patient->id, 'title' => 'z', 'starts_at' => $from->addMinute(), 'ends_at' => $from->addMinutes(30)]);
        $instant = CalendarEvent::create(['user_id' => $patient->id, 'title' => 'w', 'starts_at' => $from]);
        $pointBefore = CalendarEvent::create(['user_id' => $patient->id, 'title' => 'p', 'starts_at' => $from->subMinute()]);
        $pointAtTo = CalendarEvent::create(['user_id' => $patient->id, 'title' => 'q', 'starts_at' => $to]);

        $ids = CalendarEvent::where('user_id', $patient->id)->inRange($from, $to)->pluck('id')->all();

        $this->assertNotContains($startsAtTo->id, $ids, 'an event starting at `to` belongs to the next range');
        $this->assertNotContains($endsAtFrom->id, $ids, 'an event ending at `from` already finished');
        $this->assertContains($inside->id, $ids);
        $this->assertContains($instant->id, $ids, 'a point at `from` opens the window');
        $this->assertNotContains($pointBefore->id, $ids, 'a point before `from` is over, not open-ended');
        $this->assertNotContains($pointAtTo->id, $ids, 'a point at `to` is the next window');
    }

    public function test_personal_task_and_path_step_due_filters_ignore_the_no_date_bucket()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $patient = User::factory()->create(['role' => 'patient']);
        $disorder = Disorder::create(['name' => 'D1', 'name_ar' => 'D1']);
        $path = PatientPath::create(['patient_id' => $patient->id, 'disorder_id' => $disorder->id]);

        $from = CarbonImmutable::parse('2026-11-04 00:00:00', 'UTC');
        $to = CarbonImmutable::parse('2026-11-05 00:00:00', 'UTC');

        $noDateTask = PersonalTask::create(['user_id' => $patient->id, 'title' => 'no date']);
        $datedTask = PersonalTask::create(['user_id' => $patient->id, 'title' => 'dated', 'due_at' => $from->addHour()]);

        $this->assertEquals([$datedTask->id], PersonalTask::where('user_id', $patient->id)->inRange($from, $to)->pluck('id')->all());
        $this->assertEquals([$noDateTask->id, $datedTask->id], PersonalTask::where('user_id', $patient->id)->open()->orderBy('id')->pluck('id')->all());

        $step = PathStep::create([
            'patient_path_id' => $path->id,
            'order' => 1,
            'due_at' => $from->addHours(6),
            'due_timezone' => 'Asia/Dubai',
            'priority' => 'high',
            'category' => 'relaxation_exercise',
            'assigned_by' => $admin->id,
        ]);

        $this->assertContains($step->id, PathStep::dueInRange($from, $to)->pluck('id'));
        $this->assertSame($admin->id, $step->assignedBy->id);
        $this->assertSame('pending', $step->fresh()->status, 'the existing enum stays untouched');
    }

    public function test_live_session_carries_the_calendar_columns()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);

        $session = LiveSession::create([
            'admin_id' => $admin->id,
            'title' => 'Group',
            'livekit_room_name' => 'room_calendar_test',
            'scheduled_at' => '2026-11-05 14:30:00',
            'ends_at' => '2026-11-05 15:30:00',
            'timezone' => 'Africa/Cairo',
            'priority' => 'high',
        ]);

        $this->assertSame('scheduled', $session->fresh()->status);
        $this->assertSame('2026-11-05 15:30:00', $session->ends_at->toDateTimeString());

        $session->update([
            'previous_scheduled_at' => '2026-11-04 14:30:00',
            'rescheduled_at' => now(),
            'rescheduled_by' => $other->id,
        ]);

        $this->assertSame($other->id, $session->rescheduledBy->id);
        $this->assertNull($session->cancelled_at);
        $this->assertNull($session->reminders()->first());
    }

    public function test_the_delivery_unique_key_is_the_duplicate_guard()
    {
        $patient = User::factory()->create(['role' => 'patient']);
        $task = PersonalTask::create(['user_id' => $patient->id, 'title' => 'T']);
        $reminder = Reminder::create([
            'notifiable_type' => $task->getMorphClass(),
            'notifiable_id' => $task->getKey(),
            'reminds_at' => '2026-11-05 09:00:00',
        ]);

        ReminderDelivery::create([
            'reminder_id' => $reminder->id, 'user_id' => $patient->id, 'channel' => 'in_app', 'target_key' => 'db',
        ]);

        $this->expectException(QueryException::class);
        ReminderDelivery::create([
            'reminder_id' => $reminder->id, 'user_id' => $patient->id, 'channel' => 'in_app', 'target_key' => 'db',
        ]);
    }

    public function test_a_reminder_identity_includes_the_instant_so_rescheduling_is_a_new_cycle()
    {
        $patient = User::factory()->create(['role' => 'patient']);
        $task = PersonalTask::create(['user_id' => $patient->id, 'title' => 'T']);

        Reminder::create([
            'notifiable_type' => $task->getMorphClass(), 'notifiable_id' => $task->getKey(),
            'offset_minutes' => 60, 'reminds_at' => '2026-11-05 09:00:00', 'status' => 'cancelled',
        ]);
        Reminder::create([
            'notifiable_type' => $task->getMorphClass(), 'notifiable_id' => $task->getKey(),
            'offset_minutes' => 60, 'reminds_at' => '2026-11-06 09:00:00',
        ]);

        $this->assertCount(2, $task->reminders);

        $this->expectException(QueryException::class);
        Reminder::create([
            'notifiable_type' => $task->getMorphClass(), 'notifiable_id' => $task->getKey(),
            'offset_minutes' => 60, 'reminds_at' => '2026-11-05 09:00:00',
        ]);
    }

    public function test_scheduler_run_has_no_timestamp_pair_and_a_unique_batch()
    {
        $run = SchedulerRun::create([
            'run_at' => '2026-11-04 12:00:00', 'batch_id' => 'uuid-1', 'due_considered' => 3, 'sent' => 2, 'failed' => 1,
            'max_lateness_seconds' => 42,
        ]);

        $this->assertSame(2, $run->sent);
        $this->assertFalse($run->usesTimestamps());
        $this->assertSame('2026-11-04 12:00:00', SchedulerRun::first()->run_at->toDateTimeString());

        $this->expectException(QueryException::class);
        SchedulerRun::create(['run_at' => now(), 'batch_id' => 'uuid-1']);
    }

    public function test_declared_timezone_is_the_setting_or_the_app_default_never_utc_by_accident()
    {
        $patient = User::factory()->create(['role' => 'patient']);

        $this->assertSame((string) config('app.timezone'), $patient->declaredTimezone(), 'unset settings are not a timezone');

        $patient->settings()->create(['timezone' => 'Asia/Dubai']);
        $this->assertSame('Asia/Dubai', $patient->fresh()->declaredTimezone());

        $patient->settings()->update(['timezone' => 'Mars/Olympus']);
        $this->assertSame((string) config('app.timezone'), $patient->fresh()->declaredTimezone());
    }
}
