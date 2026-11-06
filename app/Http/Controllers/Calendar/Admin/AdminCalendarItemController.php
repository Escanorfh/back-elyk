<?php

namespace App\Http\Controllers\Calendar\Admin;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\PersonalTask;
use App\Models\User;
use App\Services\Calendar\PersonalEventService;
use App\Services\Calendar\PersonalTaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * إسناد المعالج عنصرًا لمريض: الصف يبقى مملوكًا للمريض (user_id) وcreated_by يسجّل من نسبه.
 *
 * النسبة هي مصدر معلومة «هل يعدّله المريض؟» وحدها — لا عمود حالة ثانٍ ينفصل عنها.
 * تعديل الأدمن ممكن لعنصر نسبه هو فقط؛ عناصر مرضى أخرى لا تُلمس من هنا.
 */
class AdminCalendarItemController extends Controller
{
    public function __construct(
        private readonly PersonalEventService $events,
        private readonly PersonalTaskService $tasks,
    ) {}

    public function storeEvent(Request $request, User $patient)
    {
        $this->assertPatient($patient);
        $validated = $request->validate(PersonalEventService::rules());

        return response()->json([
            'data' => $this->events->create($patient, $validated, $request->user()),
        ], 201);
    }

    public function updateEvent(Request $request, CalendarEvent $event)
    {
        Gate::authorize('update', $event);
        $validated = $request->validate(PersonalEventService::rules(required: false));

        return response()->json(['data' => $this->events->update($event, $validated)]);
    }

    public function destroyEvent(CalendarEvent $event)
    {
        Gate::authorize('delete', $event);
        $this->events->delete($event);

        return response()->json(['message' => 'Event deleted']);
    }

    public function storeTask(Request $request, User $patient)
    {
        $this->assertPatient($patient);
        $validated = $request->validate(PersonalTaskService::rules());

        return response()->json([
            'data' => $this->tasks->create($patient, $validated, $request->user()),
        ], 201);
    }

    public function updateTask(Request $request, PersonalTask $task)
    {
        Gate::authorize('update', $task);
        $validated = $request->validate(PersonalTaskService::rules(required: false));

        return response()->json(['data' => $this->tasks->update($task, $validated, $request->user())]);
    }

    public function destroyTask(PersonalTask $task)
    {
        Gate::authorize('delete', $task);
        $this->tasks->delete($task);

        return response()->json(['message' => 'Task deleted']);
    }

    private function assertPatient(User $user): void
    {
        if ($user->role !== 'patient') {
            abort(404, 'Patient not found.');
        }
    }
}
