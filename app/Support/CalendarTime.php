<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;

/**
 * كل الحسابات الزمنية المشتركة في مكان واحد: لا مقارنة بنص منسّق، ولا اشتقاق overdue في سطرين
 * مختلفين. المخزَّن دائمًا UTC؛ والتحويل للمنطقة المحلية يحدث عند العرض فقط.
 */
final class CalendarTime
{
    /** الحالات التي لم تُغلق بعد؛ خارجها لا يكون عنصرًا متأخرًا إطلاقًا. */
    private const OPEN_STATUSES = ['pending', 'active', 'scheduled', 'confirmed'];

    /** @return list<string> */
    public static function identifiers(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    /**
     * مفتوح = لم يُغلق بعد. نفس القائمة التي يشتقّ منها isOverdue سببَه، فلا يقرّر مُرسل آخر
     * «هل هذا العنصر حيّ» بقائمته الخاصة: التذكير الذي لا مستقبل له يُلغى بهذه القاعدة وحدها.
     */
    public static function isOpenStatus(?string $status): bool
    {
        return in_array((string) $status, self::OPEN_STATUSES, true);
    }

    /** منطقة IANA صالحة فقط؛ 'EST' و '+02:00' مرفوضتان لأنهما لا تحملان قواعد التوقيت الصيفي. */
    public static function isValidTimezone(?string $timezone): bool
    {
        return $timezone !== null && in_array($timezone, self::identifiers(), true);
    }

    /**
     * ISO8601 بإزاحة صريحة → الإزاحة تفوز. قيمة ساذجة → تُقرأ في منطقة العنصر. النتيجة UTC دائمًا.
     * كائن DateTime يُقبل أيضًا: لحظته محفوظة بغضّ النظر عن منطقته، فلا تزاح عند إعادة التطبيع.
     */
    public static function toUtc(mixed $value, string $timezone): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone('UTC');
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        if (! self::isValidTimezone($timezone)) {
            throw new \InvalidArgumentException("Unknown timezone: {$timezone}");
        }

        try {
            $parsed = CarbonImmutable::parse($value, new DateTimeZone($timezone));
        } catch (\Throwable) {
            throw new \InvalidArgumentException("Unparseable datetime: {$value}");
        }

        return $parsed->setTimezone('UTC');
    }

    /**
     * المتأخر دالة (due_at, status, now) وليست حالة مخزّنة — لا مصدر حقيقة ثانٍ.
     * due_at الفارغ يعني قسم «بلا تاريخ»، لا «اليوم».
     */
    public static function isOverdue(?\DateTimeInterface $dueAt, string $status, \DateTimeInterface $now): bool
    {
        if ($dueAt === null || ! in_array($status, self::OPEN_STATUSES, true)) {
            return false;
        }

        return CarbonImmutable::parse($dueAt)->lt(CarbonImmutable::parse($now));
    }

    /**
     * سلة المهمة: completed أو cancelled أو submitted أولًا، ثم overdue، ثم اليوم/القادم/بلا تاريخ.
     * «اليوم» تُحسب بتقويم المستخدم المحلي، لا بفرق UTC.
     *
     * @return string one of completed|overdue|today|upcoming|no_date
     */
    public static function bucket(?\DateTimeInterface $dueAt, string $status, \DateTimeInterface $now, string $timezone): string
    {
        if (in_array($status, ['completed', 'cancelled', 'submitted'], true)) {
            return 'completed';
        }

        if ($dueAt === null) {
            return 'no_date';
        }

        $local = $timezone === 'UTC'
            ? CarbonImmutable::parse($dueAt)
            : CarbonImmutable::parse($dueAt)->setTimezone($timezone);

        $today = self::startOfDay($now, $timezone);

        if (self::isOverdue($dueAt, $status, $now)) {
            return 'overdue';
        }

        return $local->lessThan($today->addDay()) ? 'today' : 'upcoming';
    }

    /**
     * نافذة نصف-مفتوحة مطبَّعة إلى UTC: البداية مطلوبة والنهاية اختيارية، ونهاية قبل بدايتها
     * خطأ في الطلب لا حالة مقبولة.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable|null}
     */
    public static function window(mixed $start, mixed $end, string $timezone): array
    {
        $startsAt = self::toUtc($start, $timezone);
        $endsAt = self::toUtc($end, $timezone);

        if ($startsAt === null) {
            throw new \InvalidArgumentException('A start instant is required.');
        }

        if ($endsAt !== null && $endsAt->lt($startsAt)) {
            throw new \InvalidArgumentException('The end must not be before the start.');
        }

        return [$startsAt, $endsAt];
    }

    /** منتصف النهار المحلي لليوم الذي ينتمي إليه الطابع. */
    public static function startOfDay(\DateTimeInterface $moment, string $timezone): CarbonImmutable
    {
        if (! self::isValidTimezone($timezone)) {
            throw new \InvalidArgumentException("Unknown timezone: {$timezone}");
        }

        return CarbonImmutable::parse($moment, 'UTC')->setTimezone($timezone)->startOfDay()->setTimezone('UTC');
    }

    /** منطقة العنصر، مع سقوط آمن إلى إعداد التطبيق بدل NULL غير المعلن. */
    public static function timezoneOf(?string $timezone): string
    {
        return self::isValidTimezone($timezone) ? $timezone : (string) config('app.timezone', 'UTC');
    }

    /**
     * predicate التقاطع نصف-المفتوح، في موضع واحد تستخدمه كل نماذج التقويم:
     * [start, end) ∩ [from, to)  ⇔  start < to ∧ end > from.
     * بلا عمود نهاية (أو end فارغ) تُعامَل العنصر لحظة بعرض ثانية — القدر الذي يخزنه DATETIME —
     * فلا يتهرّب من نافذة ولا يمتد إليها، ولا نختلق مدة تُنتج تعارضًا وهميًا.
     */
    public static function whereOverlaps(
        Builder $query,
        string $startColumn,
        ?string $endColumn,
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): Builder {
        return $query->where($startColumn, '<', $to)->where(function ($inner) use ($startColumn, $endColumn, $from): void {
            if ($endColumn === null) {
                $inner->where($startColumn, '>=', $from);

                return;
            }

            $inner->where(function ($instant) use ($startColumn, $endColumn, $from): void {
                $instant->whereNull($endColumn)->where($startColumn, '>=', $from);
            })->orWhere(function ($window) use ($endColumn, $from): void {
                $window->whereNotNull($endColumn)->where($endColumn, '>', $from);
            });
        });
    }

    /** @return array{from: CarbonImmutable, to: CarbonImmutable} نطاق نصف-مفتوح [from, to) */
    public static function viewRange(string $view, ?string $anchor, string $timezone): array
    {
        $base = self::isValidTimezone($timezone) ? $timezone : 'UTC';
        $moment = self::toUtc($anchor, $base) ?? CarbonImmutable::now('UTC');
        $local = $moment->setTimezone($base);

        [$from, $to] = match ($view) {
            'day' => [$local->startOfDay(), $local->startOfDay()->addDay()],
            'week' => [$local->startOfWeek(), $local->startOfWeek()->addWeek()],
            'month' => [$local->startOfMonth(), $local->startOfMonth()->addMonthNoOverflow()],
            default => [$local->startOfDay(), $local->addDays(90)->endOfDay()],
        };

        return ['from' => $from->setTimezone('UTC'), 'to' => $to->setTimezone('UTC')];
    }
}
