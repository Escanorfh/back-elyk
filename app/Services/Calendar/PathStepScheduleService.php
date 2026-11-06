<?php

namespace App\Services\Calendar;

use App\Enums\Priority;
use App\Enums\ReminderAudience;
use App\Enums\TaskCategory;
use App\Models\PathStep;
use App\Models\User;
use App\Support\CalendarTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * جدولة واجبات المسار العلاجي داخل التقويم: due_at وحده هو ما يُدخل الخطوة في نافذة، وحالتها
 * (pending|active|submitted|completed) تظل ملك مسار العلاج — لا تكتبها هذه الخدمة ولا تقرأها بوصفها موعدًا.
 *
 * العمود اسمه due_timezone (لا timezone) لأن جدول الخطوات قديم؛ التذكيرات تمشي خلف اللحظة نفسها.
 */
class PathStepScheduleService
{
    /** خطوة سُلِّمت أو اكتملت لا موعد لها في المستقبل: لا تُجدول ولا تُذكر. */
    public const SCHEDULABLE = ['pending', 'active'];

    public function __construct(private readonly ReminderSyncService $reminders) {}

    /** @return array<string, mixed> */
    public static function rules(bool $required = true): array
    {
        return [
            'due_at' => [$required ? 'required' : 'sometimes', 'date'],
            'timezone' => ['nullable', 'string', 'max:64', Rule::in(CalendarTime::identifiers())],
            'priority' => ['nullable', Rule::in(Priority::values())],
            'category' => ['nullable', Rule::in(TaskCategory::forPathStep())],
            'description' => 'nullable|string|max:5000',
            'reminder_offsets' => 'nullable|array',
            'reminder_offsets.*' => ['integer', 'min:0', 'max:20160', 'distinct'],
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function schedule(PathStep $step, array $data, User $actor): PathStep
    {
        if (! in_array((string) $step->status, self::SCHEDULABLE, true)) {
            abort(422, 'Only a pending or active step can be scheduled.');
        }

        $patient = $step->path?->patient;
        $timezone = isset($data['timezone']) && $data['timezone'] !== null
            ? CalendarTime::timezoneOf($data['timezone'])
            : ($patient?->declaredTimezone() ?? CalendarTime::timezoneOf(null));

        $payload = [];

        foreach (['priority', 'category', 'description'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        $dueChanged = array_key_exists('due_at', $data);

        if ($dueChanged) {
            $payload['due_at'] = $this->instant($data['due_at'], $timezone);
            $payload['due_timezone'] = $timezone;
        }

        $payload['assigned_by'] = $actor->id;

        $step->update($payload);

        $due = $step->fresh()?->due_at?->setTimezone('UTC');

        if ($due === null) {
            // بلا موعد لا يوجد ما يُذكَّر؛ ما تبقّى معلَّق يُلغى لا يُترك ليُرسَل لاحقًا.
            $this->reminders->cancelAll($step);
        } elseif ($this->offsets($data) !== null || $dueChanged) {
            $this->reminders->sync($step, $this->offsets($data) ?? [], $due, $timezone, ReminderAudience::Owner->value);
        }

        return $step->fresh();
    }

    /** سحب الموعد: الخطوة تعود إلى «بلا تاريخ» وتتوقف تذكيراتها المعلَّقة. */
    public function clearDate(PathStep $step): PathStep
    {
        if (! in_array((string) $step->status, self::SCHEDULABLE, true)) {
            abort(422, 'Only a pending or active step can be rescheduled.');
        }

        $step->update(['due_at' => null]);
        $this->reminders->cancelAll($step);

        return $step->fresh();
    }

    private function instant(mixed $value, string $timezone): ?CarbonImmutable
    {
        try {
            return CalendarTime::toUtc($value, $timezone);
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
