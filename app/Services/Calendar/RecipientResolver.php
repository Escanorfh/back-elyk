<?php

namespace App\Services\Calendar;

use App\Enums\ReminderAudience;
use App\Enums\ReminderChannel;
use App\Models\CalendarEvent;
use App\Models\LiveSession;
use App\Models\PathStep;
use App\Models\PersonalTask;
use App\Models\Reminder;
use App\Models\User;
use App\Support\CalendarTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * من يستحق التذكير، وأي تفضيل يحجب عنه قناة بعينها — قاعدة واحدة يقرأ منها المُشغِّل عند الإرسال
 * ويقرأ منها /me/reminders عند مزامنة الجهاز. لو تُرِك الاتجاهان يُشتقّان كلٌّ على حده يصير ما
 * يعرضه التطبيق غير ما قرّره الخادم، وهو مصدر حقيقة ثانٍ.
 *
 * لا مستقبل مخترَع: الجلسة تُشعر المريض والمدعوين والمعالج (admin_id علاقة حقيقية في الجدول،
 * والمعالج حاضر في جلسته)، والخطوة العلاجية لمريض مسارها وحده، والشخصي لصاحبه. لا نُبثّ تذكيرًا
 * لمريض على مريض آخر ولا لمعالج غير مُسند إليه.
 *
 * sessionRoster أوسع عمدًا من SessionScheduleService::audience(): تلك تُقرر «هل يعلم المريض»،
 * وهذه تُقرر «من له ميعاد في تقويمه» — والمعالج له ميعاد.
 */
final class RecipientResolver
{
    /** الأنواع الأربعة التي تولّد تذكيرات؛ لا خامس ولا جدول وسيط بينها. */
    public const SOURCES = [PersonalTask::class, CalendarEvent::class, PathStep::class, LiveSession::class];

    /**
     * @return list<int>
     */
    public function recipientIds(Reminder $reminder): array
    {
        $source = $reminder->notifiable;

        if ($source === null) {
            return [];
        }

        if ($source instanceof LiveSession) {
            return $this->sessionRoster($source, (string) $reminder->audience);
        }

        $ownerId = match (true) {
            $source instanceof PersonalTask, $source instanceof CalendarEvent => $source->user_id,
            $source instanceof PathStep => $source->path?->patient_id,
            default => null,
        };

        return $ownerId === null ? [] : [(int) $ownerId];
    }

    /**
     * التفضيل يقرّر على مستوى القناة لا على مستوى التذكير: صندوق الوارد جزء من التطبيق ولا
     * يُحجب بإعداد الدفع، أما الجهاز فيحجبه push_notifications. والجلسة تُحجب بقاعدتها الوحدها.
     *
     * @return ?string سبب مختصر يُخزَّن مع سطر التوزيع، أو null عند الاستحقاق
     */
    public function skipReason(User $user, Reminder $reminder, string $channel): ?string
    {
        $settings = $user->settings;

        // NULL ليست «مُطفأة»: أعمدة user_settings افتراضها true، فغياب الصف يعني الإرسال.
        if ($settings === null) {
            return null;
        }

        if ($reminder->notifiable instanceof LiveSession && ! (bool) $settings->session_reminders) {
            return 'preference:session_reminders';
        }

        if ($channel === ReminderChannel::LocalNotification->value && ! (bool) $settings->push_notifications) {
            return 'preference:push_notifications';
        }

        return null;
    }

    /**
     * هل المصدر حيٌّ بما يكفي ليُشعَر أحد به؟ الحالة المغلقة تلغي التذكير بدل أن تُرسله:
     * مهمة أُنجِزت أو جلسة أُلغيت لا ميعاد لها بعد الآن.
     */
    public function isSourceOpen(Model $source): bool
    {
        $status = match (true) {
            $source instanceof PersonalTask,
            $source instanceof CalendarEvent,
            $source instanceof PathStep,
            $source instanceof LiveSession => $source->status,
            default => null,
        };

        return CalendarTime::isOpenStatus($status);
    }

    /**
     * العلاقات التي يحتاجها recipientIds ليشتقّ المستقبِلين من صفّ مصدر واحد. قائمة واحدة يقرأ منها
     * المُرسِل ومسار المزامنة: لو نسي أحدهما invitedPatients صار المعالج «مستقبِلًا» في مسار لا في الآخر.
     *
     * @param  class-string<Model>  $type
     * @return list<string>
     */
    public function eagerLoads(string $type): array
    {
        return match ($type) {
            LiveSession::class => ['patient', 'admin', 'invitedPatients'],
            PathStep::class => ['path'],
            PersonalTask::class, CalendarEvent::class => ['user'],
            default => [],
        };
    }

    /**
     * مصادر هذا المستخدم، بالاتجاه المعاكس لـ recipientIds: من المستخدم إلى مصادره، في استعلام
     * واحد لكل نوع — لأن مسار المزامنة يقرأ نافذة كاملة ولا يجوز أن يستعلم لكل تذكير.
     */
    public function sourcesOwnedBy(User $user, string $type): Builder
    {
        return match ($type) {
            PersonalTask::class => PersonalTask::query()->where('user_id', $user->id),
            CalendarEvent::class => CalendarEvent::query()->where('user_id', $user->id),
            PathStep::class => PathStep::query()->whereHas(
                'path',
                fn (Builder $path): Builder => $path->where('patient_id', $user->id)
            ),
            LiveSession::class => LiveSession::query()->where(fn (Builder $q): Builder => $q
                ->where('patient_id', $user->id)
                ->orWhere('admin_id', $user->id)
                ->orWhereHas('invites', fn (Builder $invite): Builder => $invite->where('patient_id', $user->id))
            ),
            default => throw new \InvalidArgumentException("Unknown reminder source: {$type}."),
        };
    }

    /**
     * @return list<int>
     */
    private function sessionRoster(LiveSession $session, string $audience): array
    {
        if ($audience === ReminderAudience::Owner->value) {
            return $session->patient_id === null ? [] : [(int) $session->patient_id];
        }

        $ids = array_filter([
            $session->patient_id === null ? null : (int) $session->patient_id,
            $session->admin_id === null ? null : (int) $session->admin_id,
        ]);

        foreach ($session->invitedPatients as $patient) {
            $ids[] = (int) $patient->id;
        }

        return array_values(array_unique($ids));
    }
}
