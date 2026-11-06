<?php

namespace Tests\Feature\Reminders;

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\SchedulerRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * صحة المُشغِّل: أرقام فقط، باب أدمن مستقل عن مفتاح المُشغِّل، ولا سرّ ولا نصّ خطأ في المخرَج.
 */
class HealthTest extends ReminderTestSupport
{
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public function test_admin_can_read_the_health_snapshot(): void
    {
        SchedulerRun::query()->create([
            'run_at' => $this->now()->subMinute(),
            'batch_id' => (string) Str::uuid(),
            'due_considered' => 3,
            'sent' => 2,
            'failed' => 1,
            'finished_at' => $this->now()->subMinute(),
        ]);

        $this->makeReminder($this->makeTask(), [
            'status' => ReminderStatus::Pending->value,
            'reminds_at' => $this->now()->subHour(),
        ]);

        $this->makeReminder($this->makeTask(), [
            'status' => ReminderStatus::Failed->value,
            'reminds_at' => $this->now()->subHour(),
            'attempts' => 3,
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/reminders/health');

        $response->assertOk()
            ->assertJsonStructure(['data' => [
                'server_time',
                'scheduler' => [
                    'last_run_at', 'last_finished_at', 'lag_seconds', 'crashed_unfinished',
                    'batch_id', 'last_due_considered', 'last_sent', 'last_failed', 'last_max_lateness_seconds',
                ],
                'reminders' => [
                    'pending', 'pending_overdue', 'oldest_pending_overdue_at', 'sending', 'failed',
                    'exhausted', 'expired_claims', 'batch_size',
                ],
                'deliveries' => ['pending', 'failed', 'skipped', 'retryable'],
                'retention' => ['state', 'trimmed_on', 'lease_active'],
                'missed_sessions' => ['gate_open', 'past_grace_candidates'],
                'recent_runs',
            ]]);

        $this->assertSame(1, $response->json('data.reminders.pending_overdue'));
        $this->assertSame(1, $response->json('data.reminders.failed'));
        $this->assertSame(1, $response->json('data.reminders.exhausted'));
        $this->assertSame(2, $response->json('data.scheduler.last_sent'));
        $this->assertCount(1, $response->json('data.recent_runs'));
    }

    public function test_lag_seconds_is_positive_when_the_last_run_is_in_the_past(): void
    {
        SchedulerRun::query()->create([
            'run_at' => $this->now()->subMinutes(5),
            'batch_id' => (string) Str::uuid(),
            'finished_at' => $this->now()->subMinutes(5),
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/reminders/health');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(300, $response->json('data.scheduler.lag_seconds'));
    }

    public function test_a_patient_cannot_read_health(): void
    {
        $this->actingAs($this->patient)
            ->getJson('/api/v1/admin/reminders/health')
            ->assertStatus(403);
    }

    public function test_a_guest_cannot_read_health(): void
    {
        $this->getJson('/api/v1/admin/reminders/health')->assertStatus(401);
    }

    public function test_health_does_not_leak_the_scheduler_secret_or_error_text(): void
    {
        $secret = 'top-secret-cron';
        $this->configureSchedulerToken($secret);

        $this->makeReminder($this->makeTask(), [
            'status' => ReminderStatus::Failed->value,
            'reminds_at' => $this->now()->subHour(),
            'last_error' => 'patient diagnosis: severe anxiety',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/reminders/health');
        $response->assertOk();

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString($secret, $body);
        $this->assertStringNotContainsString(hash('sha256', $secret), $body);
        $this->assertStringNotContainsString('patient diagnosis', $body);
        $this->assertStringNotContainsString('last_error', $body);
    }

    public function test_health_reports_cleanup_state_and_missed_gate(): void
    {
        \App\Models\AppSetting::setValue(
            \App\Support\Reminders\ReminderSettings::TRIM_KEY,
            'idle:'.$this->now()->toDateString(),
            'string',
            \App\Support\Reminders\ReminderSettings::GROUP
        );

        $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/reminders/health');

        $this->assertSame('idle:'.$this->now()->toDateString(), $response->json('data.retention.state'));
        $this->assertSame($this->now()->toDateString(), $response->json('data.retention.trimmed_on'));
        $this->assertFalse($response->json('data.retention.lease_active'));
        $this->assertFalse($response->json('data.missed_sessions.gate_open'));
    }
}
