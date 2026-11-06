<?php

namespace App\Http\Controllers\Calendar;

use App\Enums\PersonalTaskStatus;
use App\Enums\ReminderStatus;
use App\Http\Controllers\Controller;
use App\Models\PersonalTask;
use App\Models\Reminder;
use App\Services\Calendar\PersonalTaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * مهام المريض الشخصية (CRUD + إنهاء/إعادة فتح).
 *
 * الإنهاء والإعادة ملك للمريض وحده حتى لو أنشأ المعالج المهمة، أما تعديل النص فيتبع
 * PersonalTaskPolicy — تفريق مقصود: المهمة علاجية في أصلها لكن إتمامها قرار صاحبه.
 */
class MyTaskController extends Controller
{
    public function __construct(private readonly PersonalTaskService $tasks) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(PersonalTaskStatus::values())],
        ]);

        $tasks = $request->user()->personalTasks()
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['status']))
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->get();

        $userId = (int) $request->user()->id;
        $offsets = $this->offsetMap($tasks->modelKeys());

        return response()->json([
            'data' => $tasks->map(fn (PersonalTask $t): PersonalTask => $this->markEditable($t, $userId, $offsets[$t->getKey()] ?? []))->values(),
        ]);
    }

    public function show(Request $request, PersonalTask $task)
    {
        Gate::authorize('view', $task);

        return response()->json(['data' => $this->markEditable($task, $request->user()->id)]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate(PersonalTaskService::rules());
        $task = $this->tasks->create($request->user(), $validated, $request->user());

        return response()->json(['data' => $this->markEditable($task, $request->user()->id)], 201);
    }

    public function update(Request $request, PersonalTask $task)
    {
        Gate::authorize('update', $task);
        $validated = $request->validate(PersonalTaskService::rules(required: false));
        $task = $this->tasks->update($task, $validated, $request->user());

        return response()->json(['data' => $this->markEditable($task, $request->user()->id)]);
    }

    public function complete(Request $request, PersonalTask $task)
    {
        Gate::authorize('complete', $task);
        $task = $this->tasks->complete($task);

        return response()->json(['data' => $this->markEditable($task, $request->user()->id)]);
    }

    public function reopen(Request $request, PersonalTask $task)
    {
        Gate::authorize('reopen', $task);
        $task = $this->tasks->reopen($task);

        return response()->json(['data' => $this->markEditable($task, $request->user()->id)]);
    }

    public function destroy(Request $request, PersonalTask $task)
    {
        Gate::authorize('delete', $task);
        $this->tasks->delete($task);

        return response()->json(['message' => 'Task deleted']);
    }

    /**
     * نفس مفتاح التقويم قابلًا للتعديل، فلا يحتاج العميل إلى استنتاجه من created_by.
     *
     * `reminder_offsets` ليس زينة: نموذج التحرير يعرض ما هو مجدول فعلًا، وما لا يُقرأ يُعرض فارغًا
     * وحفظُ فارغ يمسح التذكيرات التي لم يلمسها أحد. القائمة تأتي من استعلام واحد لكل صفوف index()
     * لأن الشاشة تُفتح كل يوم ولا تحتمل N+1، وتُحسب لكل صف على حدة في بقيّة المسارات.
     */
    private function markEditable(PersonalTask $task, int $userId, ?array $reminderOffsets = null): PersonalTask
    {
        $task->setAttribute('editable_by_user', $task->isEditableBy($userId));
        $task->setAttribute('reminder_offsets', $reminderOffsets ?? $this->offsetMap([$task->id])[$task->id] ?? []);

        return $task;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, list<int>>
     */
    private function offsetMap(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Reminder::query()
            ->where('notifiable_type', PersonalTask::class)
            ->whereIn('notifiable_id', $ids)
            ->where('status', ReminderStatus::Pending->value)
            ->get(['id', 'notifiable_id', 'offset_minutes'])
            ->groupBy('notifiable_id')
            ->map(fn ($rows): array => $this->distinct($rows))
            ->all();
    }

    /** @param Collection<int, Reminder> $rows @return list<int> */
    private function distinct(Collection $rows): array
    {
        $offsets = $rows->map(fn (Reminder $row): int => (int) $row->offset_minutes)->unique()->values()->all();
        sort($offsets);

        return $offsets;
    }
}
