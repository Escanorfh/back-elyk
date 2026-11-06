<?php

namespace App\Support;

use App\Models\CalendarEvent;
use App\Models\LiveSession;
use App\Models\PathStep;
use App\Models\PersonalTask;
use Illuminate\Database\Eloquent\Model;

/**
 * القراءة المشتركة عن مصدر التذكير: نوعه بلغة التقويم، وعنوانه، وميعاده، ومنطقته.
 *
 * اشتقاقٌ واحد لأربعة أنواع يستعمله صندوق الوارد ومسار مزامنة الجهاز والمُرسِّل نفسه. لو تكرّر
 * match في مكانين صار «نوع المصدر» واحتمالَين مختلفَين، وهو ما منعته هذه الطبقة أصلًا
 * (CalendarTime للسبب نفسه في الوقت).
 */
final class ReminderSource
{
    /** @param  class-string<Model>  $type */
    public static function key(string $type, int|string $id): string
    {
        // الأنواع الأربعة تتشارك تسلسلًا واحدًا للمعرّفات، فـ id وحده لا يُميّز صفًّا عن آخر.
        return $type.'#'.$id;
    }

    /** نوع المصدر بصيغة التقويم نفسها، لا اسم فئة PHP: العميل يوجّه بالنوع لا بالفئة. */
    public static function type(Model $source): string
    {
        return match (true) {
            $source instanceof LiveSession => 'live_session',
            $source instanceof PathStep => 'path_step',
            $source instanceof PersonalTask => 'personal_task',
            $source instanceof CalendarEvent => 'calendar_event',
            default => 'unknown',
        };
    }

    public static function title(Model $source): string
    {
        return trim((string) ($source->title ?? ''));
    }

    /** ميعاد العنصر نفسه، لا ميعاد التذكير: scheduled_at للجلسة، due_at للمهمة والخطوة، starts_at للحدث. */
    public static function startsAt(Model $source): ?\DateTimeInterface
    {
        $instant = match (true) {
            $source instanceof LiveSession => $source->scheduled_at,
            $source instanceof PathStep, $source instanceof PersonalTask => $source->due_at,
            $source instanceof CalendarEvent => $source->starts_at,
            default => null,
        };

        return $instant instanceof \DateTimeInterface ? $instant : null;
    }

    /** المنطقة المخزنة على العنصر؛ غيابها أو بطلانها يعني UTC لا منطقة الخادم، فلا يتزحزح ميعاد ببلد المُرسِل. */
    public static function timezone(Model $source): string
    {
        // path_steps يحمل اسمًا مختلفًا للعمود نفسه (due_timezone) لأنه وُصف قبل جدول الأحداث.
        $timezone = (string) ($source instanceof PathStep ? $source->due_timezone : $source->timezone);

        return CalendarTime::isValidTimezone($timezone) ? $timezone : 'UTC';
    }
}
