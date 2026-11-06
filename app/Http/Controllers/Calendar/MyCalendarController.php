<?php

namespace App\Http\Controllers\Calendar;

use App\Http\Controllers\Controller;
use App\Services\Calendar\CalendarAggregationService;
use App\Support\CalendarTime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * تقويم المريض: قراءة واحدة تجمع مصادره الأربعة (جلسة/خطوة/مهمة/حدث).
 *
 * المنطق كله في CalendarAggregationService — هنا يُصقل السؤال فقط. الجلسة تُقرأ من live_sessions
 * والواجب من path_steps، فلا نسخة ولا جدول وسيط يتباعد عن أصله.
 */
class MyCalendarController extends Controller
{
    public function __construct(private readonly CalendarAggregationService $calendar) {}

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->calendar->forPatient($request->user(), $this->filters($request)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'view' => ['nullable', Rule::in(['day', 'week', 'month', 'agenda'])],
            'date' => 'nullable|date',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'timezone' => ['nullable', 'string', 'max:64', Rule::in(CalendarTime::identifiers())],
            'types' => 'nullable|array',
            'types.*' => ['string', Rule::in(CalendarAggregationService::SOURCES)],
            'status' => 'nullable|string|max:255',
            'priority' => 'nullable|string|max:255',
            'q' => 'nullable|string|max:100',
        ]);
    }
}
