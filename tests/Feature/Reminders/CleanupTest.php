<?php

namespace Tests\Feature\Reminders;

use App\Enums\ReminderStatus;
use App\Models\AppSetting;
use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\SchedulerRun;
use App\Services\Calendar\ReminderRetentionService;
use App\Support\Reminders\ReminderSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * التقليم: حدود الاحتفاظ، الحذف المقيّد، إيجار يومي واحد، حماية التزامن، استرداد الإيجار الميت،
 * وعدم إعلان «اكتمل» قبل نجاح الحذف فعلًا.
 */
class CleanupTest extends ReminderTestSupport
{
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    private function retention(): ReminderRetentionService
    {
        return app(ReminderRetentionService::class);
    }

    private function trim(?CarbonImmutable $now = null): array
    {
        return $this->retention()->run($now ?? $this->now(), ReminderSettings::load());
    }

    private function trimState(): ?string
    {
        $value = AppSetting::query()->where('key', ReminderSettings::TRIM_KEY)->value('value');

        return $value === null ? null : (string) $value;
    }

    private function oldRun(int $days = 8): SchedulerRun
    {
        return SchedulerRun::query()->create([
            'run_at' => $this->now()->subDays($days),
            'batch_id' => (string) Str::uuid(),
        ]);
    }

    private function oldDelivery(Reminder $reminder, string $status, int $days = 91, ?int $userId = null): ReminderDelivery
    {
        $delivery = ReminderDelivery::query()->create([
            'reminder_id' => $reminder->id,
            'user_id' => $userId ?? $this->patient->id,
            'channel' => 'in_app',
            'target_key' => 'db',
            'status' => $status,
        ]);

        DB::table('reminder_deliveries')
            ->where('id', $delivery->id)
            ->update(['created_at' => $this->now()->subDays($days)->format('Y-m-d H:i:s')]);

        return $delivery->fresh();
    }

    public function test_runs_older_than_retention_are_deleted_and_recent_ones_kept(): void
    {
        $old = $this->oldRun(8);
        $recent = $this->oldRun(1);

        $summary = $this->trim();

        $this->assertTrue($summary['acquired']);
        $this->assertSame(1, $summary['runs_deleted']);
        $this->assertNull(SchedulerRun::query()->find($old->id));
        $this->assertNotNull(SchedulerRun::query()->find($recent->id));
        $this->assertSame('idle:'.$this->now()->toDateString(), $this->trimState());
    }

    public function test_only_terminal_deliveries_for_terminal_reminders_are_deleted(): void
    {
        $sentReminder = $this->makeReminder($this->makeTask(), [
            'status' => ReminderStatus::Sent->value,
        ]);
        $pendingReminder = $this->makeReminder($this->makeTask(), [
            'status' => ReminderStatus::Pending->value,
        ]);

        $other = \App\Models\User::factory()->create(['role' => 'patient']);

        $deletable = $this->oldDelivery($sentReminder, ReminderStatus::Sent->value);
        $failed = $this->oldDelivery($sentReminder, ReminderStatus::Failed->value, 91, $other->id);
        $pendingForOpen = $this->oldDelivery($pendingReminder, ReminderStatus::Pending->value);

        $summary = $this->trim();

        $this->assertSame(1, $summary['deliveries_deleted']);
        $this->assertNull(ReminderDelivery::query()->find($deletable->id));
        $this->assertNotNull(ReminderDelivery::query()->find($failed->id));
        $this->assertNotNull(ReminderDelivery::query()->find($pendingForOpen->id));
    }

    public function test_recent_deliveries_are_kept(): void
    {
        $reminder = $this->makeReminder($this->makeTask(), [
            'status' => ReminderStatus::Sent->value,
        ]);
        $recent = $this->oldDelivery($reminder, ReminderStatus::Sent->value, 10);

        $this->trim();

        $this->assertNotNull(ReminderDelivery::query()->find($recent->id));
    }

    public function test_cleanup_is_bounded_to_ten_thousand_rows_per_run(): void
    {
        $runAt = $this->now()->subDays(8)->format('Y-m-d H:i:s');
        $rows = [];

        for ($i = 0; $i < 10001; $i++) {
            $rows[] = [
                'run_at' => $runAt,
                'batch_id' => (string) Str::uuid(),
                'due_considered' => 0,
                'sent' => 0,
                'failed' => 0,
                'max_lateness_seconds' => null,
                'finished_at' => null,
            ];

            if (count($rows) === 500) {
                DB::table('scheduler_runs')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('scheduler_runs')->insert($rows);
        }

        $this->assertSame(10001, SchedulerRun::query()->count());

        $summary = $this->trim();

        $this->assertSame(10000, $summary['runs_deleted']);
        $this->assertSame(1, SchedulerRun::query()->count());
    }

    public function test_a_second_run_on_the_same_day_is_skipped(): void
    {
        $first = $this->trim();
        $this->assertTrue($first['acquired']);

        $second = $this->trim();

        $this->assertFalse($second['acquired']);
        $this->assertSame('already-trimmed-today', $second['reason']);
    }

    public function test_an_active_lease_blocks_concurrent_cleanup(): void
    {
        AppSetting::setValue(ReminderSettings::TRIM_KEY, 'running:abc123', 'string', ReminderSettings::GROUP);

        $summary = $this->trim();

        $this->assertFalse($summary['acquired']);
        $this->assertSame('lease-held', $summary['reason']);
        $this->assertSame('running:abc123', $this->trimState());
    }

    public function test_an_expired_lease_is_recovered(): void
    {
        $this->settingInt('claim_lease_minutes', 10);

        AppSetting::setValue(ReminderSettings::TRIM_KEY, 'running:dead', 'string', ReminderSettings::GROUP);
        DB::table('app_settings')
            ->where('key', ReminderSettings::TRIM_KEY)
            ->update(['updated_at' => $this->now()->subMinutes(11)->format('Y-m-d H:i:s')]);

        $summary = $this->trim();

        $this->assertTrue($summary['acquired']);
        $this->assertSame('idle:'.$this->now()->toDateString(), $this->trimState());
    }

    public function test_a_failure_releases_the_lease_without_marking_the_day_complete(): void
    {
        $this->oldRun(8);

        DB::listen(function ($query): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'delete')) {
                throw new RuntimeException('simulated delete failure');
            }
        });

        try {
            $this->trim();
            $this->fail('The cleanup failure should have propagated.');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated delete failure', $e->getMessage());
        }

        $state = $this->trimState();
        $this->assertSame('idle', $state);
        $this->assertNotSame('idle:'.$this->now()->toDateString(), $state);
    }

    public function test_missing_trim_state_is_recreated_as_idle(): void
    {
        AppSetting::query()->where('key', ReminderSettings::TRIM_KEY)->delete();

        $summary = $this->trim();

        $this->assertTrue($summary['acquired']);
        $this->assertSame('idle:'.$this->now()->toDateString(), $this->trimState());
    }

    public function test_pending_reminders_are_never_deleted(): void
    {
        $reminder = $this->makeReminder($this->makeTask());
        DB::table('reminders')
            ->where('id', $reminder->id)
            ->update(['created_at' => $this->now()->subDays(400)->format('Y-m-d H:i:s')]);

        $this->trim();

        $this->assertNotNull(Reminder::query()->find($reminder->id));
    }
}
