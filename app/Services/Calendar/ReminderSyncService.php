<?php

namespace App\Services\Calendar;

use App\Enums\ReminderAudience;
use App\Enums\ReminderChannel;
use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Support\CalendarTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * مزامينة التذكيرات الجدوَلة مع عنصرها: نفس قائمة الإزاحات تعني نفس الصفوف، ولا تكرار.
 *
 * الهوية الفريدة (notifiable, offset, reminds_at) تشمل اللحظة نفسها، فإعادة الجدولة دورة جديدة
 * لا تعديل للتاريخ — والملغاة تبقى تاريخًا. عند الطلب مجددًا لنفس اللحظة نفس الإزاحة يُُحيا الصف
 * بدل أن يصطدم بالقيد الفريد، أما المرسلة فلا تُمسّ: وصلت فعلًا.
 */
class ReminderSyncService
{
    /**
     * @param  list<int>|null  $offsetMinutes  null = لا مساس بالجدولة القائمة، [] = بلا تذكير
     */
    public function sync(
        Model $source,
        ?array $offsetMinutes,
        ?\DateTimeInterface $baseUtc,
        ?string $timezone = null,
        string $audience = ReminderAudience::Owner->value
    ): void {
        if ($offsetMinutes === null) {
            return;
        }

        $timezone = CalendarTime::timezoneOf($timezone ?? $source->getAttribute('timezone'));
        $desired = $this->desired($offsetMinutes, $baseUtc);
        $existing = $source->reminders()->get();

        foreach ($desired as $offset => $instant) {
            $row = $existing->first(fn (Reminder $r): bool => $r->offset_minutes === $offset && $r->reminds_at->eq($instant));

            if ($row === null) {
                $source->reminders()->create([
                    'audience' => $audience,
                    'offset_minutes' => $offset,
                    'reminds_at' => $instant,
                    'timezone' => $timezone,
                    'channel' => ReminderChannel::Auto->value,
                    'status' => ReminderStatus::Pending->value,
                ]);

                continue;
            }

            if ($row->status === ReminderStatus::Cancelled->value) {
                $row->update([
                    'status' => ReminderStatus::Pending->value,
                    'audience' => $audience,
                    'timezone' => $timezone,
                    'claimed_at' => null,
                    'attempts' => 0,
                    'last_error' => null,
                ]);
            }
        }

        // الملغاة والمرسلة تاريخ؛ pending فقط هي التي تُلغى حين تسقط من القائمة.
        $existing->each(function (Reminder $row) use ($desired): void {
            if ($row->status !== ReminderStatus::Pending->value) {
                return;
            }

            $stillWanted = isset($desired[$row->offset_minutes])
                && $desired[$row->offset_minutes]->eq($row->reminds_at);

            if (! $stillWanted) {
                $row->update(['status' => ReminderStatus::Cancelled->value]);
            }
        });
    }

    /**
     * حذف كل ما لم يُرسَل بعد لعنصر اختفى أو أُلغي — الصفوف المرسلة تبقى للسجل.
     */
    public function cancelAll(Model $source): void
    {
        $source->reminders()
            ->whereIn('status', [ReminderStatus::Pending->value, ReminderStatus::Failed->value])
            ->update(['status' => ReminderStatus::Cancelled->value]);
    }

    /**
     * @param  list<int>  $offsetMinutes
     * @return array<int, CarbonImmutable> مفهرس بالإزاحة، بلا تكرار
     */
    private function desired(array $offsetMinutes, ?\DateTimeInterface $baseUtc): array
    {
        if ($baseUtc === null) {
            return [];
        }

        $base = CarbonImmutable::parse($baseUtc)->setTimezone('UTC');

        $desired = [];

        foreach ($offsetMinutes as $offset) {
            $offset = (int) $offset;

            if ($offset < 0) {
                continue;
            }

            $desired[$offset] = $base->subMinutes($offset);
        }

        return $desired;
    }
}
