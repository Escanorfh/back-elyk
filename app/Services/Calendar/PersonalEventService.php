<?php

namespace App\Services\Calendar;

use App\Enums\CalendarEventStatus;
use App\Enums\CalendarEventType;
use App\Enums\Priority;
use App\Enums\ReminderAudience;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Support\CalendarTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * إدارة أحداث المريض الشخصية (إنشاء/تعديل/حذف) وقواعد وقتها. الكتابة كلها بعد تطبيع إلى UTC،
 * فتخزين لحظة واحدة صحيح مهما كانت المنطقة المعلنة.
 */
class PersonalEventService
{
    public function __construct(private readonly ReminderSyncService $reminders) {}

    /** @return array<string, string> قواعد التحقق، يشاركها endpoint المريض ولوحة الإدارة */
    public static function rules(bool $required = true): array
    {
        return [
            'title' => [$required ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => 'nullable|string|max:5000',
            'type' => ['nullable', Rule::in(CalendarEventType::values())],
            'starts_at' => [$required ? 'required' : 'sometimes', 'date'],
            'ends_at' => ['nullable', 'date'],
            'all_day' => 'nullable|boolean',
            'timezone' => ['nullable', 'string', 'max:64', Rule::in(CalendarTime::identifiers())],
            'location' => 'nullable|string|max:255',
            'priority' => ['nullable', Rule::in(Priority::values())],
            'status' => ['nullable', Rule::in(CalendarEventStatus::values())],
            'reminder_offsets' => 'nullable|array',
            'reminder_offsets.*' => ['integer', 'min:0', 'max:20160', 'distinct'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $owner, array $data, User $actor): CalendarEvent
    {
        $timezone = isset($data['timezone']) && $data['timezone'] !== null
            ? CalendarTime::timezoneOf($data['timezone'])
            : $owner->declaredTimezone();

        [$startsAt, $endsAt] = $this->window($data, $timezone);

        $event = CalendarEvent::create([
            'user_id' => $owner->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? CalendarEventType::PersonalReminder->value,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => (bool) ($data['all_day'] ?? false),
            'timezone' => $timezone,
            'location' => $data['location'] ?? null,
            'priority' => $data['priority'] ?? 'normal',
            'status' => $data['status'] ?? 'scheduled',
            'created_by' => $actor->id === $owner->id ? null : $actor->id,
        ]);

        $this->reminders->sync($event, $this->offsets($data), $startsAt, $timezone, ReminderAudience::Owner->value);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CalendarEvent $event, array $data): CalendarEvent
    {
        $timezone = isset($data['timezone']) && $data['timezone'] !== null
            ? CalendarTime::timezoneOf($data['timezone'])
            : CalendarTime::timezoneOf($event->timezone);

        $payload = [];

        foreach (['title', 'description', 'type', 'location', 'priority', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        if (array_key_exists('all_day', $data)) {
            $payload['all_day'] = (bool) $data['all_day'];
        }

        $start = null;

        if (array_key_exists('starts_at', $data) || array_key_exists('ends_at', $data)) {
            // الطرف الذي لم يُلمَس يعود بإزاحته الصريحة، فلا تزاح للوقت عندما يتغيّر نصف النافذة.
            [$start, $end] = $this->window([
                'starts_at' => $data['starts_at'] ?? $event->starts_at->toIso8601String(),
                'ends_at' => array_key_exists('ends_at', $data) ? $data['ends_at'] : $event->ends_at?->toIso8601String(),
            ], $timezone);

            $payload['starts_at'] = $start;
            $payload['ends_at'] = $end;
        }

        if ($timezone !== CalendarTime::timezoneOf($event->timezone)) {
            $payload['timezone'] = $timezone;
        }

        if ($payload !== []) {
            $event->update($payload);
        }

        // إعادة الجدولة تعيد توليد التذكيرات: اللحظة المرجعية تغيّرت أو الإزاحات تغيّرت.
        if ($this->offsets($data) !== null || $start !== null) {
            $this->reminders->sync(
                $event,
                $this->offsets($data),
                ($payload['starts_at'] ?? $event->starts_at)?->setTimezone('UTC'),
                $timezone,
                ReminderAudience::Owner->value
            );
        }

        return $event->fresh();
    }

    public function delete(CalendarEvent $event): void
    {
        $this->reminders->cancelAll($event);
        $event->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: CarbonImmutable, 1: CarbonImmutable|null}
     */
    private function window(array $data, string $timezone): array
    {
        try {
            return CalendarTime::window(
                $data['starts_at'] ?? '',
                $data['ends_at'] ?? null,
                $timezone
            );
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
    }

    /** @return list<int>|null */
    private function offsets(array $data): ?array
    {
        if (! array_key_exists('reminder_offsets', $data)) {
            return null;
        }

        return array_values(array_map('intval', (array) $data['reminder_offsets']));
    }
}
