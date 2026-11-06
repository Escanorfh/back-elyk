<?php

namespace Tests\Feature\Reminders;

use App\Enums\ReminderStatus;
use App\Enums\SessionStatus;
use App\Models\AppSetting;
use App\Services\Calendar\MissedSessionService;
use App\Support\CalendarTime;
use App\Support\Reminders\ReminderSettings;
use Carbon\CarbonImmutable;

/**
 * دورة الحياة: الجلسة الفائتة تُكتب مرة واحدة عبر بوّابة الحالة، والمغلقة/المباشرة/المنتهية
 * لا تُمسّ، والمتأخر يبقى دالة لا عمودًا.
 */
class LifecycleTest extends ReminderTestSupport
{
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    private function enableWidening(): void
    {
        AppSetting::setValue('calendar.status_widening_enabled', true, 'boolean', 'calendar');
    }

    private function pass(): array
    {
        return app(MissedSessionService::class)->pass($this->now(), ReminderSettings::load());
    }

    private function oldSession(array $attributes = []): \App\Models\LiveSession
    {
        return $this->makeSession(array_merge([
            'scheduled_at' => $this->now()->subMinutes(31),
            'ends_at' => $this->now()->subMinutes(1),
        ], $attributes));
    }

    public function test_a_session_past_the_grace_period_is_marked_missed_once(): void
    {
        $this->enableWidening();
        $this->settingInt('missed_grace_minutes', 30);

        $session = $this->oldSession();
        $reminder = $this->makeReminder($session, ['reminds_at' => $this->now()->subHour()]);

        $first = $this->pass();

        $this->assertSame(1, $first['considered']);
        $this->assertSame(1, $first['marked']);
        $this->assertSame(SessionStatus::Missed->value, $session->fresh()->status);
        $this->assertNotNull($session->fresh()->missed_at);
        $this->assertSame(ReminderStatus::Cancelled->value, $reminder->fresh()->status);

        $second = $this->pass();
        $this->assertSame(0, $second['considered']);
        $this->assertSame(0, $second['marked']);
    }

    public function test_a_session_inside_the_grace_period_is_not_missed(): void
    {
        $this->enableWidening();
        $this->settingInt('missed_grace_minutes', 30);

        $session = $this->makeSession([
            'scheduled_at' => $this->now()->subMinutes(10),
            'ends_at' => $this->now()->addMinutes(50),
        ]);

        $this->assertSame(0, $this->pass()['considered']);
        $this->assertSame(SessionStatus::Scheduled->value, $session->fresh()->status);
    }

    public function test_a_cancelled_session_is_excluded(): void
    {
        $this->enableWidening();

        $session = $this->oldSession(['status' => SessionStatus::Cancelled->value]);

        $summary = $this->pass();

        $this->assertSame(0, $summary['considered']);
        $this->assertSame(SessionStatus::Cancelled->value, $session->fresh()->status);
    }

    public function test_an_ended_session_is_excluded(): void
    {
        $this->enableWidening();

        $session = $this->oldSession(['status' => SessionStatus::Ended->value]);

        $this->assertSame(0, $this->pass()['considered']);
        $this->assertSame(SessionStatus::Ended->value, $session->fresh()->status);
    }

    public function test_a_live_session_is_excluded(): void
    {
        $this->enableWidening();

        $session = $this->oldSession(['status' => SessionStatus::Live->value]);

        $this->assertSame(0, $this->pass()['considered']);
        $this->assertSame(SessionStatus::Live->value, $session->fresh()->status);
    }

    public function test_a_confirmed_session_is_eligible(): void
    {
        $this->enableWidening();

        $session = $this->oldSession(['status' => SessionStatus::Confirmed->value]);

        $this->assertSame(1, $this->pass()['marked']);
        $this->assertSame(SessionStatus::Missed->value, $session->fresh()->status);
    }

    public function test_closed_status_gate_blocks_the_write_without_failing_the_pass(): void
    {
        // البوّابة مغلقة افتراضيًا: لا كتابة، لكن الدورة تنجح وتحصي المنع.
        $session = $this->oldSession();

        $summary = $this->pass();

        $this->assertSame(1, $summary['considered']);
        $this->assertSame(0, $summary['marked']);
        $this->assertSame(1, $summary['blocked']);
        $this->assertSame(SessionStatus::Scheduled->value, $session->fresh()->status);
    }

    public function test_missed_sessions_are_bounded_by_batch_size(): void
    {
        $this->enableWidening();
        $this->settingInt('batch_size', 2);

        foreach (range(1, 3) as $ignored) {
            $this->oldSession();
        }

        $summary = $this->pass();

        $this->assertSame(2, $summary['considered']);
        $this->assertSame(2, $summary['marked']);
    }

    public function test_overdue_is_derived_from_due_at_status_and_now(): void
    {
        $now = $this->now();

        $this->assertTrue(CalendarTime::isOverdue($now->subMinute(), 'pending', $now));
        $this->assertTrue(CalendarTime::isOverdue($now->subMinute(), 'active', $now));
        $this->assertFalse(CalendarTime::isOverdue($now->addMinute(), 'pending', $now));
        $this->assertFalse(CalendarTime::isOverdue($now->subMinute(), 'completed', $now));
        $this->assertFalse(CalendarTime::isOverdue($now->subMinute(), 'submitted', $now));
        $this->assertFalse(CalendarTime::isOverdue(null, 'pending', $now));
    }

    public function test_overdue_is_not_persisted_on_the_task_row(): void
    {
        $task = $this->makeTask([
            'due_at' => $this->now()->subDay(),
            'status' => 'pending',
        ]);

        // الحالة المخزّنة تبقى pending؛ التأخّر يُشتقّ عند القراءة ولا يُكتب عمودًا.
        $this->assertSame('pending', $task->fresh()->status);
        $this->assertArrayNotHasKey('overdue', $task->fresh()->getAttributes());
        $this->assertTrue(CalendarTime::isOverdue($task->fresh()->due_at, $task->fresh()->status, $this->now()));
    }
}
