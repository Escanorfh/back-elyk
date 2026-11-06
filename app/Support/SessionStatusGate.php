<?php

namespace App\Support;

use App\Enums\SessionStatus;
use App\Models\AppSetting;

/**
 * بوّابة كتابة حالات الجلسة.
 *
 * العمود وُسِّع في Phase 4.1 فيقبل confirmed|cancelled|missed قبل أن يُسمح لأي كود بكتابتها:
 * البوابة (لا المخطط) تقرّر متى تصبح تلك الحالات قابلة للكتابة، فيسقط النشر الجزئي على الجانب
 * الآمن. قيم legacy الثلاث تُكتب دائمًا حتى مع البوابة مغلقة.
 */
final class SessionStatusGate
{
    public static function wideningEnabled(): bool
    {
        return (bool) AppSetting::getValue('calendar.status_widening_enabled', false);
    }

    public static function isWritable(string $status): bool
    {
        if (! SessionStatus::contains($status)) {
            return false;
        }

        return in_array($status, SessionStatus::LEGACY, true) || self::wideningEnabled();
    }

    public static function assertWritable(string $status): void
    {
        if (! SessionStatus::contains($status)) {
            abort(422, "Unknown session status: {$status}.");
        }

        if (! self::isWritable($status)) {
            abort(409, "Session status '{$status}' is not writable yet; enable calendar.status_widening_enabled first.");
        }
    }

    /**
     * الانتقال مسموح بقاعدة App\Enums\SessionStatus وبأن تكون الوجهة قابلة للكتابة الآن.
     * حالة غير معروفة في الصف لا تُنقل أبدًا — لا نخمّن أصلًا.
     */
    public static function assertTransition(?string $from, string $to): void
    {
        $current = SessionStatus::tryFrom((string) $from);

        if ($current === null) {
            abort(409, sprintf('Session holds an unknown status "%s" and cannot be moved.', (string) $from));
        }

        self::assertWritable($to);

        $target = SessionStatus::from($to);

        if (! $current->canTransitionTo($target)) {
            abort(409, "Cannot move a session from '{$current->value}' to '{$to}'.");
        }
    }

    /** إلغاء لا يُمسّ: منتهية أو ملغاة أصلًا لا تُكتب فوقها حالة. */
    public static function isTerminal(?string $status): bool
    {
        return in_array($status, [SessionStatus::Ended->value, SessionStatus::Cancelled->value], true);
    }
}
