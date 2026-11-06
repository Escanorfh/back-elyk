<?php

namespace App\Http\Controllers\Calendar\Admin;

use App\Http\Controllers\Controller;
use App\Services\Calendar\ReminderHealthService;
use Illuminate\Http\JsonResponse;

/**
 * صحة المُشغِّل للأدمن فقط: lag والتراكم والإيجارات الميتة وحالة التقليم.
 *
 * لا محتوى ولا أسرار في المخرَج — الخدمة نفسها تُرجع أعدادًا فقط، فلا يوجد هنا ما يُستثنى.
 * المسار داخل مجموعة role:admin، فالمفتاح الخاص بالمُشغِّل لا يقرأ الصحة ولا العكس: بابان مختلفان
 * بآليتين، لأن من يدير الجدول شخصٌ مختلف عمّن يطرقه كل دقيقة.
 */
class AdminReminderHealthController extends Controller
{
    public function __construct(private readonly ReminderHealthService $health) {}

    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => $this->health->snapshot()]);
    }
}
