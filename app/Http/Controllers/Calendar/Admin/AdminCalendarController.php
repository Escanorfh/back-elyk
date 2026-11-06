<?php

namespace App\Http\Controllers\Calendar\Admin;

use App\Http\Controllers\Controller;
use App\Services\Calendar\CalendarAggregationService;
use App\Support\CalendarTime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * تقويم الإدارة: جدول أدمن نفسه، أو جدول مريض واحد عندما يُرسل patient_id.
 *
 * القراءة من نفس خدمة تقويم المريض — لا تجميع ثانٍ يعرف حالات أقل. الحصر للمرضى الذين
 * يخدمهم هذا الأدمن يبقى في الخدمة، فلا يُترك البوابة للـ query string.
 */
class AdminCalendarController extends Controller
{
    public function __construct(private readonly CalendarAggregationService $calendar) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'patient_id' => 'nullable|integer|exists:users,id',
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

        return response()->json([
            'data' => $this->calendar->forAdmin($request->user(), $filters),
        ]);
    }
}
