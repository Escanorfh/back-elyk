<?php

namespace Tests\Feature\Reminders;

use App\Enums\ReminderAudience;
use App\Enums\ReminderChannel;
use App\Enums\ReminderStatus;
use App\Models\AppSetting;
use App\Models\LiveSession;
use App\Models\PersonalTask;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Calendar\ConflictDetectionService;
use App\Services\Calendar\PathStepScheduleService;
use App\Services\Calendar\PersonalEventService;
use App\Services\Calendar\PersonalTaskService;
use App\Services\Calendar\RecipientResolver;
use App\Services\Calendar\ReminderSyncService;
use App\Services\Calendar\SessionScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * محرّك التذكير: الأنواع الأربعة، التوقيت المطلق UTC، إعادة الجدولة، الإلغاء، منع التكرار،
 * واستخراج المستقبِلين من المصدر لا من التخمين.
 */
class ReminderEngineTest extends ReminderTestSupport
{
    private ReminderSyncService $sync;

    private PersonalTaskService $tasks;

    private PersonalEventService $events;

    private PathStepScheduleService $steps;

    private SessionScheduleService $sessions;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->sync = new ReminderSyncService;
        $conflicts = new ConflictDetectionService;

        $this->tasks = new PersonalTaskService($this->sync);
        $this->events = new PersonalEventService($this->sync);
        $this->steps = new PathStepScheduleService($this->sync);
        $this->sessions = new SessionScheduleService($this->sync, $conflicts);
    }

    private function enableWidening(): void
    {
        AppSetting::setValue('calendar.status_widening_enabled', true, 'boolean', 'calendar');
    }

    public function test_session_creation_generates_participant_reminders(): void
    {
        $session = $this->sessions->create($this->admin, [
            'title' => 'Therapy',
            'scheduled_at' => '2026-11-06T19:00:00+00:00',
            'timezone' => 'UTC',
            'patient_id' => $this->patient->id,
            'reminder_offsets' => [30, 10],
        ]);

        $reminders = $session->reminders()->orderByDesc('offset_minutes')->get();

        $this->assertCount(2, $reminders);
        $this->assertSame([30, 10], $reminders->pluck('offset_minutes')->all());
        $this->assertSame(
            ['2026-11-06 18:30:00', '2026-11-06 18:50:00'],
            $reminders->map(fn (Reminder $r): string => $r->reminds_at->setTimezone('UTC')->format('Y-m-d H:i:s'))->all()
        );
        $this->assertTrue($reminders->every(fn (Reminder $r): bool => $r->audience === ReminderAudience::Participants->value));
        $this->assertTrue($reminders->every(fn (Reminder $r): bool => $r->status === ReminderStatus::Pending->value));
    }

    public function test_personal_task_creation_uses_the_task_timezone_for_utc(): void
    {
        $task = $this->tasks->create($this->patient, [
            'title' => 'Exercise',
            'due_at' => '2026-11-06 18:00:00',
            'timezone' => 'Africa/Cairo',
            'reminder_offsets' => [30],
        ], $this->patient);

        $reminder = $task->reminders()->sole();

        $this->assertSame('2026-11-06 15:30:00', $reminder->reminds_at->setTimezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertSame('Africa/Cairo', $reminder->timezone);
        $this->assertSame(ReminderAudience::Owner->value, $reminder->audience);
    }

    public function test_path_step_schedule_creates_an_owner_reminder(): void
    {
        $step = $this->makeStep();

        $this->steps->schedule($step, [
            'due_at' => '2026-11-06T18:00:00+00:00',
            'timezone' => 'UTC',
            'reminder_offsets' => [15],
        ], $this->admin);

        $reminder = $step->reminders()->sole();

        $this->assertSame(15, $reminder->offset_minutes);
        $this->assertSame('2026-11-06 17:45:00', $reminder->reminds_at->setTimezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertSame(ReminderAudience::Owner->value, $reminder->audience);
    }

    public function test_calendar_event_creation_creates_an_owner_reminder(): void
    {
        $event = $this->events->create($this->patient, [
            'title' => 'Meditation',
            'starts_at' => '2026-11-06T18:00:00+00:00',
            'timezone' => 'UTC',
            'reminder_offsets' => [60],
        ], $this->patient);

        $reminder = $event->reminders()->sole();

        $this->assertSame(60, $reminder->offset_minutes);
        $this->assertSame('2026-11-06 17:00:00', $reminder->reminds_at->setTimezone('UTC')->format('Y-m-d H:i:s'));
    }

    public function test_rescheduling_cancels_old_reminders_and_creates_new_ones(): void
    {
        $session = $this->sessions->create($this->admin, [
            'title' => 'Therapy',
            'scheduled_at' => '2026-11-06T19:00:00+00:00',
            'timezone' => 'UTC',
            'patient_id' => $this->patient->id,
            'reminder_offsets' => [30],
        ]);

        $old = $session->reminders()->sole();

        $this->sessions->update($session, ['scheduled_at' => '2026-11-07T19:00:00+00:00']);

        $old->refresh();
        $this->assertSame(ReminderStatus::Cancelled->value, $old->status);

        $new = $session->reminders()->where('status', ReminderStatus::Pending->value)->sole();
        $this->assertSame('2026-11-07 18:30:00', $new->reminds_at->setTimezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertNotSame($old->id, $new->id);
    }

    public function test_rescheduling_preserves_already_sent_history(): void
    {
        $session = $this->sessions->create($this->admin, [
            'title' => 'Therapy',
            'scheduled_at' => '2026-11-06T19:00:00+00:00',
            'timezone' => 'UTC',
            'patient_id' => $this->patient->id,
            'reminder_offsets' => [30, 10],
        ]);

        $sent = $session->reminders()->where('offset_minutes', 30)->sole();
        $sent->update(['status' => ReminderStatus::Sent->value, 'sent_at' => CarbonImmutable::now('UTC')]);

        $this->sessions->update($session, ['scheduled_at' => '2026-11-07T19:00:00+00:00']);

        $this->assertSame(ReminderStatus::Sent->value, $sent->fresh()->status);
        $this->assertSame(1, $session->reminders()->where('status', ReminderStatus::Sent->value)->count());

        $pending = $session->reminders()->where('status', ReminderStatus::Pending->value)->get();
        $this->assertCount(1, $pending);
        $this->assertSame(10, $pending->first()->offset_minutes);
    }

    public function test_cancelling_a_session_cancels_pending_reminders(): void
    {
        $this->enableWidening();

        $session = $this->sessions->create($this->admin, [
            'title' => 'Therapy',
            'scheduled_at' => '2026-11-06T19:00:00+00:00',
            'timezone' => 'UTC',
            'patient_id' => $this->patient->id,
            'reminder_offsets' => [30, 10],
        ]);

        $this->sessions->cancel($session, 'Sick');

        $this->assertSame(
            2,
            $session->reminders()->where('status', ReminderStatus::Cancelled->value)->count()
        );
    }

    public function test_syncing_the_same_schedule_twice_does_not_duplicate(): void
    {
        $base = CarbonImmutable::parse('2026-11-06T19:00:00+00:00');

        $this->sync->sync($this->makeTask(), [30, 10], $base, 'UTC');
        $task = PersonalTask::query()->sole();
        $this->sync->sync($task, [30, 10], $base, 'UTC');

        $this->assertSame(2, $task->reminders()->count());
    }

    public function test_removing_an_offset_cancels_only_that_pending_reminder(): void
    {
        $base = CarbonImmutable::parse('2026-11-06T19:00:00+00:00');
        $task = $this->makeTask();

        $this->sync->sync($task, [30, 10], $base, 'UTC');
        $this->sync->sync($task, [30], $base, 'UTC');

        $this->assertSame(
            ReminderStatus::Cancelled->value,
            $task->reminders()->where('offset_minutes', 10)->sole()->status
        );
        $this->assertSame(
            ReminderStatus::Pending->value,
            $task->reminders()->where('offset_minutes', 30)->sole()->status
        );
    }

    public function test_session_participants_include_patient_therapist_and_invitees(): void
    {
        $invited = User::factory()->create(['role' => 'patient']);

        $session = $this->sessions->create($this->admin, [
            'title' => 'Group',
            'scheduled_at' => '2026-11-06T19:00:00+00:00',
            'timezone' => 'UTC',
            'patient_id' => $this->patient->id,
            'patient_ids' => [$this->patient->id, $invited->id],
            'reminder_offsets' => [30],
        ]);

        $reminder = $session->reminders()->sole();
        $resolver = new RecipientResolver;

        $ids = $resolver->recipientIds($reminder);
        sort($ids);

        $expected = [$this->admin->id, $this->patient->id, $invited->id];
        sort($expected);

        $this->assertSame($expected, $ids);
    }

    public function test_owner_audience_resolves_only_the_patient(): void
    {
        $session = $this->makeSession(['patient_id' => $this->patient->id]);
        $reminder = $this->makeReminder($session, ['audience' => ReminderAudience::Owner->value]);

        $this->assertSame([$this->patient->id], (new RecipientResolver)->recipientIds($reminder));
    }

    public function test_task_event_and_step_resolve_their_real_owner(): void
    {
        $resolver = new RecipientResolver;

        $this->assertSame(
            [$this->patient->id],
            $resolver->recipientIds($this->makeReminder($this->makeTask()))
        );
        $this->assertSame(
            [$this->patient->id],
            $resolver->recipientIds($this->makeReminder($this->makeEvent()))
        );
        $this->assertSame(
            [$this->patient->id],
            $resolver->recipientIds($this->makeReminder($this->makeStep()))
        );
    }

    public function test_a_deleted_source_has_no_recipients(): void
    {
        $task = $this->makeTask();
        $reminder = $this->makeReminder($task);
        $task->delete();

        $this->assertSame([], (new RecipientResolver)->recipientIds($reminder->fresh()));
    }

    public function test_disabled_session_reminders_filter_the_recipient(): void
    {
        $this->settingsFor($this->patient, ['session_reminders' => false]);

        $session = $this->makeSession(['patient_id' => $this->patient->id]);
        $reminder = $this->makeReminder($session);

        $resolver = new RecipientResolver;
        $patient = $this->patient->fresh();

        $this->assertSame(
            'preference:session_reminders',
            $resolver->skipReason($patient, $reminder, ReminderChannel::InApp->value)
        );
    }

    public function test_disabled_push_filters_local_channel_but_not_in_app(): void
    {
        $this->settingsFor($this->patient, ['push_notifications' => false]);

        $reminder = $this->makeReminder($this->makeTask());
        $resolver = new RecipientResolver;
        $patient = $this->patient->fresh();

        $this->assertNull($resolver->skipReason($patient, $reminder, ReminderChannel::InApp->value));
        $this->assertSame(
            'preference:push_notifications',
            $resolver->skipReason($patient, $reminder, ReminderChannel::LocalNotification->value)
        );
    }

    public function test_missing_settings_row_does_not_block_delivery(): void
    {
        $reminder = $this->makeReminder($this->makeTask());

        $this->assertNull(
            (new RecipientResolver)->skipReason($this->patient, $reminder, ReminderChannel::InApp->value)
        );
    }

    public function test_closed_sources_are_not_open(): void
    {
        $resolver = new RecipientResolver;

        $this->assertTrue($resolver->isSourceOpen($this->makeSession()));
        $this->assertFalse($resolver->isSourceOpen($this->makeSession(['status' => 'cancelled'])));
        $this->assertFalse($resolver->isSourceOpen($this->makeSession(['status' => 'ended'])));
        $this->assertTrue($resolver->isSourceOpen($this->makeTask(['status' => 'pending'])));
        $this->assertFalse($resolver->isSourceOpen($this->makeTask(['status' => 'completed'])));
    }
}
