<?php

namespace Tests\Feature\Reminders;

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\SchedulerRun;
use App\Services\Calendar\LocalReminderSyncService;
use App\Services\Calendar\ReminderDispatcher;
use App\Support\Reminders\ReminderSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * التسليم داخل التطبيق، منع التكرار، حدود إعادة المحاولة، حالات التخطي، وعقد مزامنة الجهاز.
 * كل تأكيد يقرأ صفّ التوزيع لا نيّة الكود.
 */
class DeliveryTest extends ReminderTestSupport
{
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    private function deadline(): int
    {
        return hrtime(true) + 10_000_000_000;
    }

    private function dispatch(?CarbonImmutable $now = null): array
    {
        return app(ReminderDispatcher::class)->dispatch(
            $now ?? $this->now(),
            ReminderSettings::load(),
            $this->deadline()
        );
    }

    private function dueReminderFor(object $source, array $attributes = []): Reminder
    {
        return $this->makeReminder($source, array_merge([
            'reminds_at' => $this->now()->subMinute(),
        ], $attributes));
    }

    public function test_in_app_delivery_writes_a_delivery_and_a_database_notification(): void
    {
        $reminder = $this->dueReminderFor($this->makeTask(['title' => 'Walk']));

        $summary = $this->dispatch();

        $this->assertSame(1, $summary['sent']);
        $this->assertSame(ReminderStatus::Sent->value, $reminder->fresh()->status);

        $delivery = ReminderDelivery::query()->sole();
        $this->assertSame('in_app', $delivery->channel);
        $this->assertSame('db', $delivery->target_key);
        $this->assertSame(ReminderStatus::Sent->value, $delivery->status);
        // attempts يعدّ محاولات إعادة الإرسال لا أول إرسال ناجح؛ نجاح أول مرة يبقى صفرًا.
        $this->assertSame(0, $delivery->attempts);
        $this->assertNotNull($delivery->sent_at);

        $row = DB::table('notifications')->sole();
        $payload = json_decode((string) $row->data, true);
        $this->assertSame('calendar_reminder', $payload['type']);
        $this->assertSame('personal_task', $payload['source']);
        $this->assertSame((int) $reminder->id, $payload['reminder_id']);
        $this->assertSame('Walk', $payload['source_title']);
    }

    public function test_running_dispatch_twice_does_not_duplicate_delivery(): void
    {
        $this->dueReminderFor($this->makeTask());

        $this->dispatch();
        $this->dispatch();

        $this->assertSame(1, ReminderDelivery::query()->count());
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_failed_delivery_records_error_and_defers_retry(): void
    {
        $this->settingInt('retry_max_attempts', 3);
        $this->settingInt('claim_lease_minutes', 10);

        $this->mock(NotificationDispatcher::class, function ($mock): void {
            $mock->shouldReceive('send')->andThrow(new RuntimeException('mail transport down'));
        });

        $reminder = $this->dueReminderFor($this->makeTask());

        $summary = $this->dispatch();

        $this->assertSame(1, $summary['failed']);

        $reminder->refresh();
        $this->assertSame(ReminderStatus::Failed->value, $reminder->status);
        $this->assertSame(1, $reminder->attempts);
        $this->assertSame('delivery failed', $reminder->last_error);

        $delivery = ReminderDelivery::query()->sole();
        $this->assertSame(ReminderStatus::Failed->value, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertStringContainsString('mail transport down', (string) $delivery->error);
        $this->assertNotNull($delivery->next_retry_at);
        $this->assertTrue($delivery->next_retry_at->gt($this->now()));
    }

    public function test_retry_limit_stops_further_attempts(): void
    {
        $this->settingInt('retry_max_attempts', 1);

        $this->mock(NotificationDispatcher::class, function ($mock): void {
            $mock->shouldReceive('send')->andThrow(new RuntimeException('permanent failure'));
        });

        $reminder = $this->dueReminderFor($this->makeTask());

        $this->dispatch();

        $delivery = ReminderDelivery::query()->sole();
        $this->assertSame(1, $delivery->attempts);
        $this->assertNull($delivery->next_retry_at);

        $second = $this->dispatch();
        $this->assertSame(0, $second['considered']);
        $this->assertSame(1, ReminderDelivery::query()->sole()->attempts);
        $this->assertSame(ReminderStatus::Failed->value, $reminder->fresh()->status);
    }

    public function test_a_deferred_retry_is_released_without_burning_an_attempt(): void
    {
        $this->settingInt('retry_max_attempts', 3);
        $this->settingInt('claim_lease_minutes', 10);

        $this->mock(NotificationDispatcher::class, function ($mock): void {
            $mock->shouldReceive('send')->andThrow(new RuntimeException('temporary'));
        });

        $reminder = $this->dueReminderFor($this->makeTask());

        $this->dispatch();
        $this->assertSame(1, ReminderDelivery::query()->sole()->attempts);

        $second = $this->dispatch();

        $this->assertSame(1, $second['released']);
        $this->assertSame(ReminderStatus::Pending->value, $reminder->fresh()->status);
        $this->assertSame(1, ReminderDelivery::query()->sole()->attempts);
    }

    public function test_recipient_preference_skips_the_delivery(): void
    {
        $this->settingsFor($this->patient, ['session_reminders' => false]);

        $session = $this->makeSession(['patient_id' => $this->patient->id]);
        $reminder = $this->dueReminderFor($session);

        $summary = $this->dispatch();

        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(ReminderStatus::Skipped->value, $reminder->fresh()->status);

        $delivery = ReminderDelivery::query()->sole();
        $this->assertSame(ReminderStatus::Skipped->value, $delivery->status);
        $this->assertSame('preference:session_reminders', $delivery->error);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_session_without_a_patient_is_skipped_without_a_delivery_row(): void
    {
        $session = $this->makeSession(['patient_id' => null]);
        $reminder = $this->dueReminderFor($session, ['audience' => 'owner']);

        $summary = $this->dispatch();

        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(ReminderStatus::Skipped->value, $reminder->fresh()->status);
        $this->assertSame(0, ReminderDelivery::query()->count());
    }

    public function test_closed_source_is_cancelled_not_sent(): void
    {
        $task = $this->makeTask(['status' => 'completed']);
        $reminder = $this->dueReminderFor($task);

        $summary = $this->dispatch();

        $this->assertSame(1, $summary['cancelled']);
        $this->assertSame(ReminderStatus::Cancelled->value, $reminder->fresh()->status);
        $this->assertSame('source closed', $reminder->fresh()->last_error);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_deleted_source_is_cancelled_not_sent(): void
    {
        $task = $this->makeTask();
        $reminder = $this->dueReminderFor($task);
        $task->delete();

        $summary = $this->dispatch();

        $this->assertSame(1, $summary['cancelled']);
        $this->assertSame(ReminderStatus::Cancelled->value, $reminder->fresh()->status);
        $this->assertSame('source deleted', $reminder->fresh()->last_error);
    }

    public function test_local_window_returns_only_eligible_pending_reminders(): void
    {
        $task = $this->makeTask(['title' => 'Evening walk']);
        $inside = $this->makeReminder($task, [
            'offset_minutes' => 30,
            'reminds_at' => $this->now()->addHour(),
        ]);

        $this->makeReminder($this->makeTask(), [
            'offset_minutes' => 10,
            'reminds_at' => $this->now()->addDays(10),
        ]);

        $service = app(LocalReminderSyncService::class);
        $rows = $service->window($this->patient, $this->now(), $this->now()->addDay());

        $this->assertCount(1, $rows);
        $this->assertSame((int) $inside->id, $rows[0]['id']);
        $this->assertSame('personal_task', $rows[0]['source']);
        $this->assertSame('Evening walk', $rows[0]['source_title']);
        $this->assertFalse($rows[0]['acked']);
    }

    public function test_local_window_hides_reminders_when_push_is_disabled(): void
    {
        $this->settingsFor($this->patient, ['push_notifications' => false]);

        $this->makeReminder($this->makeTask(), ['reminds_at' => $this->now()->addHour()]);

        $rows = app(LocalReminderSyncService::class)
            ->window($this->patient, $this->now(), $this->now()->addDay());

        $this->assertSame([], $rows);
    }

    public function test_local_ack_is_idempotent_and_uses_the_agreed_target_key(): void
    {
        $reminder = $this->makeReminder($this->makeTask(), ['reminds_at' => $this->now()->addHour()]);

        $service = app(LocalReminderSyncService::class);

        $first = $service->ack($this->patient, $reminder);
        $second = $service->ack($this->patient, $reminder);

        $this->assertFalse($first['already_acked']);
        $this->assertTrue($second['already_acked']);

        $delivery = ReminderDelivery::query()->sole();
        $this->assertSame('local_notification', $delivery->channel);
        $this->assertSame('local:default', $delivery->target_key);
        $this->assertSame(ReminderStatus::Sent->value, $delivery->status);

        $rows = $service->window($this->patient, $this->now(), $this->now()->addDay());
        $this->assertTrue($rows[0]['acked']);
    }

    public function test_local_window_is_scoped_to_the_requesting_user(): void
    {
        $stranger = \App\Models\User::factory()->create(['role' => 'patient']);
        $this->makeReminder($this->makeTask(), ['reminds_at' => $this->now()->addHour()]);

        $rows = app(LocalReminderSyncService::class)
            ->window($stranger, $this->now(), $this->now()->addDay());

        $this->assertSame([], $rows);
    }

    public function test_scheduler_run_is_not_created_by_a_bare_dispatch(): void
    {
        $this->dueReminderFor($this->makeTask());

        $this->dispatch();

        $this->assertSame(0, SchedulerRun::query()->count());
    }
}
