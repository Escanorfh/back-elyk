<?php

namespace App\Http\Controllers;

use App\Services\Calendar\ReminderScheduler;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * endpoint المُشغِّل: نداء cron خارجي واحد = دورة معالجة كاملة، بلا عملية مقيمة على Wasmer.
 *
 * الحماية في AuthenticateSchedulerToken وحدها؛ هذا المسار خارج auth:sanctum عمدًا — مستخدم مُصادَق
 * عليه لا يملك أن يُشغِّل الجدول، والجدول لا يملك أن يكون مُصادَقًا عليه بـ bearer token.
 *
 * المُخرَج أرقام فقط: لا نصّ سريري ولا عنوان مريض ولا رسالة استثناء. تفصيل الفشل يذهب إلى log
 * بسطرين (batch_id + stage)، ومن يقرأ الاستجابة لا يحتاج أكثر من أن يعرف هل دار شيء أم لا.
 */
class SchedulerController extends Controller
{
    public function __construct(private readonly ReminderScheduler $scheduler) {}

    public function dispatch(): JsonResponse
    {
        try {
            return response()->json(['ok' => true] + $this->scheduler->run());
        } catch (Throwable) {
            // السجلّ كتب السبب بالفعل؛ هنا لا يُضاف شيء قابل للتسريب إلى مَن طرق الباب.
            return response()->json([
                'ok' => false,
                'message' => 'Scheduler run failed.',
            ], 500);
        }
    }
}
