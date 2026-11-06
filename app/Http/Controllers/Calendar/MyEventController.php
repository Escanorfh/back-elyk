<?php

namespace App\Http\Controllers\Calendar;

use App\Enums\CalendarEventStatus;
use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Services\Calendar\PersonalEventService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * أحداث المريض الشخصية (CRUD). الكتابة كلها عبر PersonalEventService: التطبيع إلى UTC،
 * النافذة القانونية، ومزامنة التذكيرات — البوابة هنا مالكة الصف فقط.
 */
class MyEventController extends Controller
{
    public function __construct(private readonly PersonalEventService $events) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(CalendarEventStatus::values())],
        ]);

        $events = $request->user()->calendarEvents()
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['status']))
            ->orderBy('starts_at')
            ->get();

        return response()->json([
            'data' => $events->map(fn (CalendarEvent $e): CalendarEvent => $this->markEditable($e, $request->user()->id))->values(),
        ]);
    }

    public function show(Request $request, CalendarEvent $event)
    {
        Gate::authorize('view', $event);

        return response()->json(['data' => $this->markEditable($event, $request->user()->id)]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate(PersonalEventService::rules());
        $event = $this->events->create($request->user(), $validated, $request->user());

        return response()->json(['data' => $this->markEditable($event, $request->user()->id)], 201);
    }

    public function update(Request $request, CalendarEvent $event)
    {
        Gate::authorize('update', $event);
        $validated = $request->validate(PersonalEventService::rules(required: false));
        $event = $this->events->update($event, $validated);

        return response()->json(['data' => $this->markEditable($event, $request->user()->id)]);
    }

    public function destroy(Request $request, CalendarEvent $event)
    {
        Gate::authorize('delete', $event);
        $this->events->delete($event);

        return response()->json(['message' => 'Event deleted']);
    }

    /** نفس مفتاح التقويم قابلًا للتعديل، فلا يحتاج العميل إلى استنتاجه من created_by. */
    private function markEditable(CalendarEvent $event, int $userId): CalendarEvent
    {
        $event->setAttribute('editable_by_user', $event->isEditableBy($userId));

        return $event;
    }
}
