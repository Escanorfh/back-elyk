<?php

namespace Tests\Feature\Reminders;

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\SchedulerRun;
use App\Services\Calendar\ReminderDispatcher;
use App\Services\Calendar\ReminderScheduler;
use App\Support\Reminders\ReminderSettings;
use Carbon\CarbonImmutable;

/**
 * المُشغِّل: الباب المحمي، الدفعات المحدودة، المطالبات المتداخلة، استرداد الإيجار الميت،
 * وكتابة scheduler_runs. كل اختبار يقيس قرارًا واحدًا في الدورة.
 */
class SchedulerTest extends ReminderTestSupport
{
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    private function dueReminder(int $offsetMinutes = 30, ?CarbonImmutable $remindsAt = null): Reminder
    {
        $task = $this->makeTask(['due_at' => $this->now()->addHour()]);

        return $this->makeReminder($task, [
            'offset_minutes' => $offsetMinutes,
            'reminds_at' => $remindsAt ?? $this->now()->subMinute(),
        ]);
    }

    public function test_authorized_request_runs_the_scheduler(): void
    {
        $this->configureSchedulerToken('cron-secret');
        $this->dueReminder();

        $response = $this->withHeader('x-scheduler-token', 'cron-secret')
            ->postJson('/api/v1/scheduler/reminders');

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertNotNull($response->json('batch_id'));
        $this->assertSame(1, SchedulerRun::query()->count());
    }

    public function test_unconfigured_scheduler_returns_503(): void
    {
        $response = $this->postJson('/api/v1/scheduler/reminders');

        $response->assertStatus(503);
        $this->assertSame(0, SchedulerRun::query()->count());
    }

    public function test_wrong_token_is_rejected_without_running(): void
    {
        $this->configureSchedulerToken('cron-secret');

        $this->withHeader('x-scheduler-token', 'not-the-secret')
            ->postJson('/api/v1/scheduler/reminders')
            ->assertStatus(401);

        $this->assertSame(0, SchedulerRun::query()->count());
    }

    public function test_due_reminder_is_delivered_and_marked_sent(): void
    {
        $this->configureSchedulerToken('cron-secret');
        $reminder = $this->dueReminder();

        $this->withHeader('x-scheduler-token', 'cron-secret')
            ->postJson('/api/v1/scheduler/reminders')
            ->assertOk();

        $reminder->refresh();
        $this->assertSame(ReminderStatus::Sent->value, $reminder->status);
        $this->assertNotNull($reminder->sent_at);

        $delivery = ReminderDelivery::query()->where('reminder_id', $reminder->id)->sole();
        $this->assertSame('in_app', $delivery->channel);
        $this->assertSame('db', $delivery->target_key);
        $this->assertSame(ReminderStatus::Sent->value, $delivery->status);

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_batch_size_bounds_how_many_reminders_are_considered(): void
    {
        $this->configureSchedulerToken('cron-secret');
        $this->settingInt('batch_size', 2);

        foreach (range(1, 5) as $ignored) {
            $this->dueReminder();
        }

        $summary = app(ReminderScheduler::class)->run($this->now());

        $this->assertSame(2, $summary['due_considered']);
        $this->assertSame(2, $summary['sent']);
        $this->assertSame(3, Reminder::query()->where('status', ReminderStatus::Pending->value)->count());

        $run = SchedulerRun::query()->sole();
        $this->assertSame(2, $run->due_considered);
        $this->assertSame(2, $run->sent);
    }

    public function test_a_reminder_claimed_by_another_run_is_not_sent_again(): void
    {
        $this->configureSchedulerToken('cron-secret');
        $reminder = $this->dueReminder();
        $reminder->update([
            'status' => ReminderStatus::Sending->value,
            'claimed_at' => $this->now(),
        ]);

        $summary = app(ReminderScheduler::class)->run($this->now());

        $this->assertSame(0, $summary['sent']);
        $this->assertSame(ReminderStatus::Sending->value, $reminder->fresh()->status);
        $this->assertSame(0, ReminderDelivery::query()->count());
    }

    public function test_expired_sending_claims_are_recovered(): void
    {
        $this->settingInt('claim_lease_minutes', 10);

        $reminder = $this->dueReminder();
        $reminder->update([
            'status' => ReminderStatus::Sending->value,
            'claimed_at' => $this->now()->subMinutes(11),
        ]);

        $released = app(ReminderDispatcher::class)
            ->releaseExpiredClaims($this->now(), ReminderSettings::load());

        $this->assertSame(1, $released);

        $reminder->refresh();
        $this->assertSame(ReminderStatus::Pending->value, $reminder->status);
        $this->assertNull($reminder->claimed_at);
        $this->assertSame(1, $reminder->attempts);
    }

    public function test_expired_claim_is_recovered_and_delivered_in_the_same_run(): void
    {
        $this->settingInt('claim_lease_minutes', 10);

        $reminder = $this->dueReminder();
        $reminder->update([
            'status' => ReminderStatus::Sending->value,
            'claimed_at' => $this->now()->subMinutes(11),
        ]);

        $summary = app(ReminderScheduler::class)->run($this->now());

        $this->assertSame(1, $summary['claims_recovered']);
        $this->assertSame(1, $summary['sent']);
        $this->assertSame(ReminderStatus::Sent->value, $reminder->fresh()->status);
    }

    public function test_scheduler_run_records_counters_and_lateness(): void
    {
        $this->configureSchedulerToken('cron-secret');
        $this->dueReminder(remindsAt: $this->now()->subMinutes(2));

        app(ReminderScheduler::class)->run($this->now());

        $run = SchedulerRun::query()->sole();
        $this->assertNotNull($run->finished_at);
        $this->assertSame(1, $run->due_considered);
        $this->assertSame(1, $run->sent);
        $this->assertSame(0, $run->failed);
        $this->assertGreaterThanOrEqual(120, $run->max_lateness_seconds);
        $this->assertSame(36, strlen($run->batch_id));
    }

    public function test_reminders_scheduled_in_the_future_are_not_processed(): void
    {
        $task = $this->makeTask();
        $reminder = $this->makeReminder($task, [
            'reminds_at' => $this->now()->addHour(),
        ]);

        $summary = app(ReminderScheduler::class)->run($this->now());

        $this->assertSame(0, $summary['due_considered']);
        $this->assertSame(ReminderStatus::Pending->value, $reminder->fresh()->status);
    }
}
