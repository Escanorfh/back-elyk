<?php

namespace App\Http\Controllers\Calendar;

use App\Http\Controllers\Controller;
use App\Models\Reminder;
use App\Services\Calendar\LocalReminderSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * نافذة مزامنة الإشعارات المحلية: يقرأها الهاتف ليجدول على نفسه، ويُقِرّ بما خطّطه.
 *
 * خارج مجموعة role:patient ولو أنها /me/…: المعالج طرف في الجلسات وله تذكيراتها، فحصر المسار
 * بالمريض كان يجعل تذكير المعالج يُرسل لصندوقه ولا يصل لوقته على جهازه.
 *
 * الاستجابة بلا أي نصّ علاجي: عنوان العنصر وميعاده ونوعه — والفحص أن هذا الشخص مستقبِل فعلي لا
 * أنه يملك الجدول.
 */
class MyReminderController extends Controller
{
    /** لا نافذة بلا سقف: طلب by from/to مفتوح يعني جدولًا كاملًا في ذاكرة هاتف. */
    private const MAX_WINDOW_DAYS = 92;

    public function __construct(private readonly LocalReminderSyncService $sync) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $now = CarbonImmutable::now('UTC');

        $from = isset($filters['from'])
            ? CarbonImmutable::parse($filters['from'])->setTimezone('UTC')
            : $now->subDay();

        $to = isset($filters['to'])
            ? CarbonImmutable::parse($filters['to'])->setTimezone('UTC')
            : $now->addDays(30);

        if ($to->lt($from)) {
            return response()->json(['message' => 'The window ends before it starts.'], 422);
        }

        if ($from->diffInDays($to) > self::MAX_WINDOW_DAYS) {
            return response()->json(['message' => 'The window may not exceed '.self::MAX_WINDOW_DAYS.' days.'], 422);
        }

        return response()->json([
            'data' => $this->sync->window($request->user(), $from, $to),
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'server_time' => $now->toIso8601String(),
        ]);
    }

    public function ack(Request $request, Reminder $reminder): JsonResponse
    {
        return response()->json(['data' => $this->sync->ack($request->user(), $reminder)]);
    }
}
