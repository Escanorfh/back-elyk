<?php

namespace Tests\Feature\Reminders;

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * نافذة مزامنة الجهاز عبر HTTP: نطاق محدود، عزل المستخدم، إقرار idempotent، والمعالج يرى جلسته.
 */
class MyReminderApiTest extends ReminderTestSupport
{
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    private function upcomingReminderFor(object $source, array $attributes = []): Reminder
    {
        return $this->makeReminder($source, array_merge([
            'reminds_at' => $this->now()->addHour(),
        ], $attributes));
    }

    public function test_a_guest_cannot_read_the_window(): void
    {
        $this->getJson('/api/v1/me/reminders')->assertStatus(401);
    }

    public function test_a_patient_sees_only_their_own_window(): void
    {
        $mine = $this->upcomingReminderFor($this->makeTask(['title' => 'Mine']));

        $stranger = User::factory()->create(['role' => 'patient']);
        $this->upcomingReminderFor($this->makeTask(['user_id' => $stranger->id, 'title' => 'Theirs']));

        $response = $this->actingAs($this->patient)
            ->getJson('/api/v1/me/reminders')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame((int) $mine->id, $response->json('data.0.id'));
        $this->assertSame('Mine', $response->json('data.0.source_title'));
        $this->assertSame('personal_task', $response->json('data.0.source'));
        $this->assertFalse($response->json('data.0.acked'));
        $this->assertArrayHasKey('window', $response->json());
        $this->assertArrayHasKey('server_time', $response->json());
    }

    public function test_a_window_longer_than_ninety_two_days_is_rejected(): void
    {
        $this->actingAs($this->patient)
            ->getJson('/api/v1/me/reminders?from=2026-01-01&to=2026-04-10')
            ->assertStatus(422);
    }

    public function test_a_window_that_ends_before_it_starts_is_rejected(): void
    {
        $this->actingAs($this->patient)
            ->getJson('/api/v1/me/reminders?from=2026-11-10&to=2026-11-01')
            ->assertStatus(422);
    }

    public function test_ack_is_idempotent_and_creates_one_local_delivery(): void
    {
        $reminder = $this->upcomingReminderFor($this->makeTask());

        $first = $this->actingAs($this->patient)
            ->postJson("/api/v1/me/reminders/{$reminder->id}/ack")
            ->assertOk();

        $this->assertFalse($first->json('data.already_acked'));

        $second = $this->actingAs($this->patient)
            ->postJson("/api/v1/me/reminders/{$reminder->id}/ack")
            ->assertOk();

        $this->assertTrue($second->json('data.already_acked'));

        $delivery = ReminderDelivery::query()->sole();
        $this->assertSame('local_notification', $delivery->channel);
        $this->assertSame('local:default', $delivery->target_key);
        $this->assertSame(ReminderStatus::Sent->value, $delivery->status);
    }

    public function test_ack_rejects_a_reminder_that_belongs_to_someone_else(): void
    {
        $reminder = $this->upcomingReminderFor($this->makeTask());
        $stranger = User::factory()->create(['role' => 'patient']);

        $this->actingAs($stranger)
            ->postJson("/api/v1/me/reminders/{$reminder->id}/ack")
            ->assertStatus(404);

        $this->assertSame(0, ReminderDelivery::query()->count());
    }

    public function test_ack_rejects_a_closed_source(): void
    {
        $task = $this->makeTask(['status' => 'completed']);
        $reminder = $this->upcomingReminderFor($task);

        $this->actingAs($this->patient)
            ->postJson("/api/v1/me/reminders/{$reminder->id}/ack")
            ->assertStatus(404);
    }

    public function test_a_therapist_receives_the_session_reminder_on_their_own_device(): void
    {
        $session = $this->makeSession(['patient_id' => $this->patient->id]);
        $reminder = $this->upcomingReminderFor($session, ['audience' => 'participants']);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/me/reminders')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame((int) $reminder->id, $response->json('data.0.id'));
        $this->assertSame('live_session', $response->json('data.0.source'));

        $this->actingAs($this->admin)
            ->postJson("/api/v1/me/reminders/{$reminder->id}/ack")
            ->assertOk()
            ->assertJsonPath('data.already_acked', false);
    }

    public function test_the_window_excludes_a_disabled_push_preference(): void
    {
        $this->settingsFor($this->patient, ['push_notifications' => false]);

        $this->upcomingReminderFor($this->makeTask());

        $response = $this->actingAs($this->patient)
            ->getJson('/api/v1/me/reminders')
            ->assertOk();

        $this->assertSame([], $response->json('data'));
    }

    public function test_the_window_excludes_reminders_outside_the_requested_range(): void
    {
        $this->makeReminder($this->makeTask(), ['reminds_at' => $this->now()->addDays(5)]);

        $response = $this->actingAs($this->patient)
            ->getJson('/api/v1/me/reminders?from='.$this->now()->toDateString().'&to='.$this->now()->addDay()->toDateString())
            ->assertOk();

        $this->assertSame([], $response->json('data'));
    }
}
