<?php

namespace App\Notifications;

use App\Models\Reminder;
use App\Support\ReminderSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

/**
 * التذكير المستحق في صندوق الوارد — قناة database وحدها.
 *
 * لا broadcast: ناقل الإنتاج log فلا شيء يصل، وإضافته توحي بمسار غير موجود. لا FcmChannel: الدفع
 * مؤجَّل بقرار المرحلة، وإدراج القناة يعني وعدًا بتوصيل لا ينفّذه أحد. ولا mail: مِحدّ mail هو log
 * أيضًا، والتذكير رسالة قصيرة لا إشعار بريد يومي.
 *
 * data لا يحمل وصفًا علاجيًا: العنوان والميعاد والمعرّفات فقط. ما هو أكثر من ذلك يُقرأ في شاشة
 * العنصر بعد فتحه، فلا يبقى نصّ إكلينيكي في سجلّات الصندوق وفي أي نسخة منه.
 *
 * type/source_id هما عقد الفتح: شاشة العنصر تُقصَد بهما، فـ sent لا تعني مقروءًا ولا تُغني عن read_at.
 */
class ReminderDueNotification extends Notification
{
    public function __construct(
        private readonly Reminder $reminder,
        private readonly Model $source,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $language = (string) ($notifiable->settings?->language ?? 'ar');
        $title = ReminderSource::title($this->source);
        $when = $this->when($language);

        return [
            'type' => 'calendar_reminder',
            'message' => $language === 'en'
                ? "Reminder: {$title} — {$when}"
                : "تذكير: {$title} — {$when}",
            'reminder_id' => (int) $this->reminder->id,
            'offset_minutes' => (int) $this->reminder->offset_minutes,
            'reminds_at' => $this->reminder->reminds_at?->toIso8601String(),
            'source' => ReminderSource::type($this->source),
            'source_id' => (int) $this->source->getKey(),
            'source_title' => $title,
            'source_starts_at' => ReminderSource::startsAt($this->source)?->format(DATE_ATOM),
        ];
    }

    private function when(string $language): string
    {
        $start = ReminderSource::startsAt($this->source);

        if ($start === null) {
            return $language === 'en' ? 'now' : 'الآن';
        }

        // منطقة المصدر لا منطقة الخادم: العنصر يحمل timezone خاصًا والعرض للمريض بلغته ومنطقته.
        return CarbonImmutable::instance($start)
            ->setTimezone(ReminderSource::timezone($this->source))
            ->locale($language === 'en' ? 'en' : 'ar')
            ->isoFormat('DD/MM/YYYY HH:mm');
    }
}
