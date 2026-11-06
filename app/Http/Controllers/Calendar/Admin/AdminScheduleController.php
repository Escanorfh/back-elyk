<?php

namespace App\Http\Controllers\Calendar\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Calendar\ConflictDetectionService;
use App\Services\Calendar\SessionScheduleService;
use App\Support\CalendarTime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * أدوات الاختيار قبل الحفظ: توافر مريض وفحص تعارض صريح.
 *
 * كلاهما يقرأ من نفس نوافذ التقويم نصف-مفتوحة، فلا يجيب الأدمن «متاح» بينما يعرض
 * التقويم شيئًا في الموضع نفسه. لا كتابة هنا إطلاقًا — فحص فقط.
 */
class AdminScheduleController extends Controller
{
    public function __construct(
        private readonly ConflictDetectionService $conflicts,
        private readonly SessionScheduleService $sessions,
    ) {}

    public function availability(Request $request)
    {
        $validated = $request->validate([
            'patient_id' => ['required', 'integer', 'exists:users,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'timezone' => ['nullable', 'string', 'max:64', Rule::in(CalendarTime::identifiers())],
        ]);

        $patient = User::findOrFail((int) $validated['patient_id']);
        $timezone = isset($validated['timezone']) && $validated['timezone'] !== null
            ? CalendarTime::timezoneOf($validated['timezone'])
            : $patient->declaredTimezone();

        $from = CalendarTime::toUtc($validated['from'], $timezone);
        $to = CalendarTime::toUtc($validated['to'], $timezone);

        if ($from === null || $to === null || $to->lte($from)) {
            abort(422, 'The availability window needs a start earlier than its end.');
        }

        return response()->json([
            'data' => $this->conflicts->availability((int) $patient->id, $from, $to)
                + ['timezone' => $timezone],
        ]);
    }

    public function conflicts(Request $request)
    {
        $validated = $request->validate(SessionScheduleService::conflictRules());

        return response()->json([
            'data' => $this->sessions->previewConflicts($request->user(), $validated),
        ]);
    }
}
