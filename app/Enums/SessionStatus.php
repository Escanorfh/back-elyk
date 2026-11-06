<?php

namespace App\Enums;

/**
 * live_sessions.status. The column is a plain varchar (Phase 4.1) and this enum is the only place
 * that names its legal values and legal moves, so no driver-specific CHECK or enum is trusted.
 */
enum SessionStatus: string
{
    use HasValues;

    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case Live = 'live';
    case Ended = 'ended';
    case Cancelled = 'cancelled';
    case Missed = 'missed';

    /** القيم الموجودة أصلًا قبل التقويم — تُكتب دائمًا مهما كانت حالة البوابة. */
    public const LEGACY = ['scheduled', 'live', 'ended'];

    /** ما يُعرض للمريض في «القادمة» — لا ملغاة ولا فائتة. */
    public const UPCOMING = ['scheduled', 'confirmed'];

    /** الحالات التي يُقبل فيها الدفع أو إشعار الجدولة. */
    public const BOOKED = ['scheduled', 'confirmed'];

    /** ما يقبل دفع المريض: القادمة أو البثّ الجاري (لم يتغيّر سلوك ما قبل التقويم، زُيد confirmed فقط). */
    public const PAYABLE = ['scheduled', 'confirmed', 'live'];

    /** ما يُغلقه الإنهاء: القادمة والمباشرة. ملغاة/فائتة/منتهية لا تُكتب فوقها حالة. */
    public const CLOSABLE = ['scheduled', 'confirmed', 'live'];

    /** ما يظهر في قائمة المريض: القادمة + المباشرة؛ لا ملغاة ولا فائتة ولا منتهية. */
    public const PATIENT_LIST = ['scheduled', 'confirmed', 'live'];

    public function isLegacy(): bool
    {
        return in_array($this->value, self::LEGACY, true);
    }

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            // إعادة الجدولة تُرجئ الجلسة إلى scheduled؛ لا تُشعل لاييفًا ولا تُنهي واحدة منتهية.
            // الإنهاء من scheduled|confirmed يُسمح به: زرّ «أنهِ» القديم كان يغلق جلسة لم تُشعل قط.
            self::Scheduled => [self::Confirmed, self::Live, self::Ended, self::Cancelled, self::Missed, self::Scheduled],
            self::Confirmed => [self::Live, self::Ended, self::Cancelled, self::Missed, self::Scheduled],
            self::Live => [self::Ended],
            self::Cancelled => [self::Scheduled],
            self::Missed => [self::Scheduled],
            self::Ended => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->next(), true);
    }
}
