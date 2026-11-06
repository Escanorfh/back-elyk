<?php

namespace App\Services\Calendar;

use App\Enums\SessionStatus;
use App\Models\LiveSession;
use App\Support\Reminders\ReminderSettings;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * الجلسات التي مضى ميعادها ولم تُشعل ولم تُلغَ: بعد فترة السماح تُقفل فائتة.
 *
 * الكتابة كلها عبر SessionScheduleService::markMissed — لا سطر هنا يحدّث live_sessions مباشرة،
 * وإلا صار قانون الحالة مُطبَّقًا في موضعين (وهو نفس ما يحميه LiveKitWebhookController).
 *
 * whereIn(status, BOOKED) تُخرِج المباشرة والمنتهية والملغاة والفائتة من الاختيار قبل أن تبدأ،
 * فالسؤال «هل تُعلَّم فائتة؟» لا يُترك للصدفة: session لا تزال live لا يُمسّ إطلاقًا.
 */
final class MissedSessionService
{
    public function __construct(private readonly SessionScheduleService $sessions) {}

    /**
     * @return array{considered: int, marked: int, blocked: int}
     */
    public function pass(CarbonImmutable $now, ReminderSettings $settings): array
    {
        $cutoff = $now->subMinutes($settings->missedGraceMinutes());

        $ids = LiveSession::query()
            ->whereIn('status', SessionStatus::BOOKED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $cutoff)
            ->orderBy('scheduled_at')
            ->limit($settings->batchSize())
            ->pluck('id')
            ->all();

        $marked = 0;
        $blocked = 0;

        foreach ($ids as $id) {
            $session = LiveSession::find($id);

            // القائمة رُشّحت قبل لحظة؛ webhook أو أدمن قد يكون نقل الجلسة في الأثناء.
            // إعادة فحص الحالة قبل الكتابة هي ما يجعل «تُحدَّث مرة واحدة» ساريًا لا مقصودًا فقط.
            if ($session === null || ! in_array((string) $session->status, SessionStatus::BOOKED, true)) {
                continue;
            }

            try {
                $this->sessions->markMissed($session);
                $marked++;
            } catch (HttpException) {
                // البوابة مغلقة أو الحالة لم تعد تقبل الانتقال: تخطٍّ صامت لا خطأ، والدورة القادمة تعيد.
                $blocked++;
            } catch (Throwable) {
                $blocked++;
            }
        }

        return ['considered' => count($ids), 'marked' => $marked, 'blocked' => $blocked];
    }
}
