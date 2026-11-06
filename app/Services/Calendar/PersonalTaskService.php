<?php

namespace App\Services\Calendar;

use App\Enums\PersonalTaskStatus;
use App\Enums\Priority;
use App\Enums\ReminderAudience;
use App\Enums\TaskCategory;
use App\Models\PersonalTask;
use App\Models\User;
use App\Support\CalendarTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * مهام المريض الشخصية: إنشاء/تعديل/حذف/إنهاء/إعادة فتح. الإنهاء والإعادة فعل للمالك وحده،
 * أما تعديل المحتوى فيحرم على المريض إذا نسب المعالج المهمة (created_by هو المصدر الوحيد).
 */
class PersonalTaskService
{
    public function __construct(private readonly ReminderSyncService $reminders) {}

    /** @return array<string, string> */
    public static function rules(bool $required = true): array
    {
        return [
            'title' => [$required ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => 'nullable|string|max:5000',
            'category' => ['nullable', Rule::in(TaskCategory::forPersonalTask())],
            'due_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64', Rule::in(CalendarTime::identifiers())],
            'priority' => ['nullable', Rule::in(Priority::values())],
            'status' => ['nullable', Rule::in(PersonalTaskStatus::values())],
            'reminder_offsets' => 'nullable|array',
            'reminder_offsets.*' => ['integer', 'min:0', 'max:20160', 'distinct'],
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function create(User $owner, array $data, User $actor): PersonalTask
    {
        $timezone = isset($data['timezone']) && $data['timezone'] !== null
            ? CalendarTime::timezoneOf($data['timezone'])
            : $owner->declaredTimezone();

        $dueAt = $this->instant($data['due_at'] ?? null, $timezone);

        $task = PersonalTask::create([
            'user_id' => $owner->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? TaskCategory::PersonalTask->value,
            'due_at' => $dueAt,
            'timezone' => $timezone,
            'priority' => $data['priority'] ?? 'normal',
            'status' => $data['status'] ?? 'pending',
            'created_by' => $actor->id === $owner->id ? null : $actor->id,
        ]);

        $this->reminders->sync($task, $this->offsets($data), $dueAt, $timezone, ReminderAudience::Owner->value);

        return $task;
    }

    /**
     * الحالة قرار المريض وحده: تعديل النص للمعالج الذي نسَب المهمة، أما completed/cancelled
     * فيمرّ من /complete و /reopen — فلا يشهد أحد أن المريض أنجز تمرينه نيابة عنه.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(PersonalTask $task, array $data, User $actor): PersonalTask
    {
        if (array_key_exists('status', $data)
            && $data['status'] !== null
            && (string) $data['status'] !== (string) $task->status
            && $actor->id !== $task->user_id) {
            abort(403, 'Only the patient changes a task state.');
        }

        $timezone = isset($data['timezone']) && $data['timezone'] !== null
            ? CalendarTime::timezoneOf($data['timezone'])
            : CalendarTime::timezoneOf($task->timezone);

        $payload = [];

        foreach (['title', 'description', 'category', 'priority', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        $dueChanged = array_key_exists('due_at', $data);

        if ($dueChanged) {
            $payload['due_at'] = $this->instant($data['due_at'], $timezone);
        }

        if ($timezone !== CalendarTime::timezoneOf($task->timezone)) {
            $payload['timezone'] = $timezone;
        }

        // الإكمال عبر endpoint خاص حتى لا يُنسى completed_at عند كتابة الحالة نصًا.
        if (($payload['status'] ?? null) === 'completed') {
            $payload['completed_at'] = $task->completed_at ?? now();
        }

        if (in_array($payload['status'] ?? null, ['pending', 'cancelled'], true)) {
            $payload['completed_at'] = null;
        }

        if ($payload !== []) {
            $task->update($payload);
        }

        if ($this->offsets($data) !== null || $dueChanged) {
            $this->reminders->sync(
                $task,
                $this->offsets($data),
                $task->fresh()?->due_at?->setTimezone('UTC'),
                $timezone,
                ReminderAudience::Owner->value
            );
        }

        return $task->fresh();
    }

    public function complete(PersonalTask $task): PersonalTask
    {
        if ((string) $task->status === 'cancelled') {
            abort(422, 'A cancelled task is not completed. Reopen it first.');
        }

        $task->update(['status' => 'completed', 'completed_at' => $task->completed_at ?? now()]);

        return $task->fresh();
    }

    public function reopen(PersonalTask $task): PersonalTask
    {
        if ((string) $task->status === 'pending') {
            abort(422, 'The task is already open.');
        }

        $task->update(['status' => 'pending', 'completed_at' => null]);

        return $task->fresh();
    }

    public function delete(PersonalTask $task): void
    {
        $this->reminders->cancelAll($task);
        $task->delete();
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
