<?php

namespace Tests\Feature\Reminders;

use App\Models\AppSetting;
use App\Models\CalendarEvent;
use App\Models\Disorder;
use App\Models\LiveSession;
use App\Models\LiveSessionPatient;
use App\Models\PathStep;
use App\Models\PatientPath;
use App\Models\PersonalTask;
use App\Models\Reminder;
use App\Models\User;
use App\Models\UserSettings;
use App\Support\Reminders\ReminderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * قاعدة مشتركة لاختبارات المرحلة السادسة: بناء المصادر الأربعة، وضبط إعدادات المُشغِّل،
 * وكتابة تذكير مباشرة. لا منطق إنتاج هنا — مجرّد ترتيب بيانات يعرف المخطط.
 */
abstract class ReminderTestSupport extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->patient = User::factory()->create(['role' => 'patient']);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function settingsFor(User $user, array $attributes = []): UserSettings
    {
        return UserSettings::create(array_merge(['user_id' => $user->id], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    protected function makeSession(array $attributes = []): LiveSession
    {
        return LiveSession::create(array_merge([
            'admin_id' => $this->admin->id,
            'title' => 'Session',
            'status' => 'scheduled',
            'scheduled_at' => '2026-11-06 19:00:00',
            'ends_at' => '2026-11-06 20:00:00',
            'timezone' => 'UTC',
            'livekit_room_name' => 'room_'.Str::random(10),
        ], $attributes));
    }

    protected function invite(LiveSession $session, User $patient): LiveSessionPatient
    {
        return LiveSessionPatient::create([
            'live_session_id' => $session->id,
            'patient_id' => $patient->id,
            'approval_status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function makeTask(array $attributes = []): PersonalTask
    {
        return PersonalTask::create(array_merge([
            'user_id' => $this->patient->id,
            'title' => 'Task',
            'due_at' => '2026-11-06 18:00:00',
            'timezone' => 'UTC',
            'status' => 'pending',
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    protected function makeEvent(array $attributes = []): CalendarEvent
    {
        return CalendarEvent::create(array_merge([
            'user_id' => $this->patient->id,
            'title' => 'Event',
            'starts_at' => '2026-11-06 18:00:00',
            'timezone' => 'UTC',
            'status' => 'scheduled',
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    protected function makeStep(array $attributes = []): PathStep
    {
        $disorder = Disorder::create(['name' => 'D-'.Str::random(6), 'name_ar' => 'د']);

        $path = PatientPath::create([
            'patient_id' => $this->patient->id,
            'disorder_id' => $disorder->id,
            'status' => 'active',
        ]);

        return PathStep::create(array_merge([
            'patient_path_id' => $path->id,
            'order' => 1,
            'title' => 'Step',
            'status' => 'active',
            'due_at' => '2026-11-06 18:00:00',
            'due_timezone' => 'UTC',
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    protected function makeReminder(object $source, array $attributes = []): Reminder
    {
        return Reminder::create(array_merge([
            'notifiable_type' => $source->getMorphClass(),
            'notifiable_id' => $source->getKey(),
            'audience' => 'owner',
            'offset_minutes' => 30,
            'reminds_at' => '2026-11-06 17:30:00',
            'timezone' => 'UTC',
            'channel' => 'auto',
            'status' => 'pending',
        ], $attributes));
    }

    protected function configureSchedulerToken(string $secret): void
    {
        AppSetting::setValue(
            ReminderSettings::TOKEN_KEY,
            ReminderSettings::fingerprint($secret),
            'string',
            ReminderSettings::GROUP
        );
    }

    protected function settingInt(string $name, int $value): void
    {
        AppSetting::setValue('reminders.'.$name, $value, 'integer', ReminderSettings::GROUP);
    }

    protected function settingString(string $name, string $value): void
    {
        AppSetting::setValue('reminders.'.$name, $value, 'string', ReminderSettings::GROUP);
    }
}
