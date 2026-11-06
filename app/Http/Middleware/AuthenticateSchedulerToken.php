<?php

namespace App\Http\Middleware;

use App\Support\Reminders\ReminderSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * باب المُشغِّل: مفتاح في الترويسة فقط، مُخزَّنًا بصمةً (sha256) في app_settings.reminders.scheduler_token.
 *
 * لا .env ولا migration: القرار المتفق عليه هو الصف المزروع أصلًا في 4.1، والبصمة وحدها ما يسقط في
 * الجدول، فلا تسريب ذو معنى لو طابقت نسخة احتياطية أو ظهر محتواها في سجلّ.
 *
 * لا سرّ في المسار: cron-job.org يسجّل الروابط، ورمزٌ في query string يعني رمزًا في سجلّ طرف ثالث.
 * hash_equals على البصمتين لا على النصَّين: مقارنة النصوص تتوقّف عند أول حرف مختلف، فزمنها قناة تسريب.
 *
 * غياب المفتاح يرفض الخدمة (503) ولا يفتحها: endpoint يعمل بلا حماية أسوأ من endpoint معطَّل،
 * وهذا هو الوضع مباشرة بعد التثبيت، قبل أن يضع المشغِّل المفتاح.
 */
class AuthenticateSchedulerToken
{
    public const HEADER = 'x-scheduler-token';

    public function handle(Request $request, Closure $next): Response
    {
        $expected = ReminderSettings::load()->schedulerTokenHash();

        if ($expected === null) {
            return response()->json(['message' => 'Scheduler is not configured.'], 503);
        }

        $provided = $request->header(self::HEADER);

        if (! is_string($provided) || ! $this->matches($provided, $expected)) {
            Log::warning('rejected scheduler call', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Unauthorised.'], 401);
        }

        return $next($request);
    }

    private function matches(string $provided, string $expected): bool
    {
        $provided = trim($provided);

        if ($provided === '') {
            return false;
        }

        return hash_equals($expected, ReminderSettings::fingerprint($provided));
    }
}
