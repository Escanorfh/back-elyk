<?php

namespace App\Services\Calendar;

use App\Enums\ReminderChannel;
use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\User;
use App\Support\ReminderSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * عقد مزامنة الإشعارات المحلية: الخادم لا يستطيع أن يُوقظ شاشة هاتف، فيُقرأ ما يجب أن يُوقَظ عليه.
 *
 * النافذة تُرجع صفوف reminders بـ reminds_at المطلق (UTC) كما هو — لا تُعيد حسبان الإزاحة عند العرض،
 * فقاعدة 4.1 أن اللحظة تُحسب مرة عند الإنشاء. الجهاز يحوّلها لتوقيت جهازه عند الجدولة، ومن هنا لا
 * تُذكر منطقة الخادم في المسار إطلاقًا.
 *
 * المستقبِلون يُشتقّون بنفس recipientIds التي يستعملها المُرسِل، والتفضيل بنفس skipReason: ما يراه
 * التطبيق هو ما قرّره الخادم لا نسخة عنه. لا مصدر مغلق ولا مصدر محذوف يخرج من النافذة، فيلغيه
 * الجهاز من تلقاء نفسه وهو يتّفق الفارق بمعرّفات ثابتة.
 *
 * الإقرار (ack) سطرُ reminder_deliveries بقناة local_notification ومفتاح local:default — وهو يُعلم
 * أن الجهاز خطّط، ولا يدّعي أنه أيقظ أحدًا: لا سجلّ وصول من النظام ولا device registry، فأي وعد
 * أكبر من ذلك كذب.
 */
final class LocalReminderSyncService
{
    private const CHANNEL = ReminderChannel::LocalNotification;

    private const TARGET_KEY = 'local:default';

    /** سقف الصفوف لكل نوع داخل النافذة: لا قوائم بلا حدّ داخل طلب هاتف على شبكة ضعيفة. */
    private const MAX_ROWS_PER_TYPE = 200;

    public function __construct(private readonly RecipientResolver $recipients) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function window(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = collect();

        foreach (RecipientResolver::SOURCES as $type) {
            $found = Reminder::query()
                ->where('notifiable_type', $type)
                ->where('status', ReminderStatus::Pending->value)
                ->whereBetween('reminds_at', [$from, $to])
                ->whereIn('notifiable_id', $this->recipients->sourcesOwnedBy($user, $type)->select('id'))
                ->orderBy('reminds_at')
                ->limit(self::MAX_ROWS_PER_TYPE)
                ->get();

            $rows = $rows->merge($found);
        }

        if ($rows->isEmpty()) {
            return [];
        }

        $sources = $this->loadSources($rows);
        $acked = $this->ackedIds($user, $rows->pluck('id')->all());

        $eligible = [];

        foreach ($rows as $reminder) {
            $source = $sources[ReminderSource::key($reminder->notifiable_type, $reminder->notifiable_id)] ?? null;

            if ($source === null || ! $this->recipients->isSourceOpen($source)) {
                continue;
            }

            if (! in_array((int) $user->id, $this->recipients->recipientIds($reminder), true)) {
                continue;
            }

            if ($this->recipients->skipReason($user, $reminder, self::CHANNEL->value) !== null) {
                continue;
            }

            $startsAt = ReminderSource::startsAt($source);

            $eligible[] = [
                'id' => (int) $reminder->id,
                'offset_minutes' => (int) $reminder->offset_minutes,
                'reminds_at' => $reminder->reminds_at?->toIso8601String(),
                'source' => ReminderSource::type($source),
                'source_id' => (int) $source->getKey(),
                'source_title' => ReminderSource::title($source),
                'source_starts_at' => $startsAt?->format(DATE_ATOM),
                'source_timezone' => ReminderSource::timezone($source),
                'acked' => in_array((int) $reminder->id, $acked, true),
            ];
        }

        usort($eligible, fn (array $a, array $b): int => strcmp((string) $a['reminds_at'], (string) $b['reminds_at']));

        return $eligible;
    }

    /**
     * إقرار بأن الجهاز خطّط هذا التذكير. تكرار النداء لا يُنتج سطرًا ثانيًا ولا فشلًا: القيد الفريد
     * هو الحكم، فالعودة بـ already_acked أفضل من 409 يدفع الهاتف لإعادة المحاولة كل دورة.
     *
     * @return array{reminder_id: int, status: string, already_acked: bool}
     */
    public function ack(User $user, Reminder $reminder): array
    {
        $source = $reminder->notifiable;

        abort_if(
            $source === null || ! $this->recipients->isSourceOpen($source),
            404,
            'This reminder is no longer scheduled.'
        );

        abort_unless(in_array((int) $user->id, $this->recipients->recipientIds($reminder), true), 404);

        // التفضيل المُطفأ يُبقي الصف pending: لم يقرّ جهاز بأنّه خطّط شيئًا، ولا خطأ في ذلك.
        abort_if($this->recipients->skipReason($user, $reminder, self::CHANNEL->value) !== null, 403, 'Notifications are disabled.');

        $existing = ReminderDelivery::query()
            ->where('reminder_id', $reminder->id)
            ->where('user_id', $user->id)
            ->where('channel', self::CHANNEL->value)
            ->where('target_key', self::TARGET_KEY)
            ->first();

        if ($existing !== null) {
            return [
                'reminder_id' => (int) $reminder->id,
                'status' => (string) $existing->status,
                'already_acked' => true,
            ];
        }

        $now = CarbonImmutable::now('UTC');

        try {
            ReminderDelivery::query()->create([
                'reminder_id' => $reminder->id,
                'user_id' => $user->id,
                'channel' => self::CHANNEL->value,
                'target_key' => self::TARGET_KEY,
                'status' => ReminderStatus::Sent->value,
                'attempted_at' => $now,
                'sent_at' => $now,
            ]);
        } catch (Throwable) {
            // متداخلان أقرّا في نفس اللحظة: الفائز هو مَن كتب السطر، والنتيجة واحدة بالنسبة للجهاز.
            return [
                'reminder_id' => (int) $reminder->id,
                'status' => ReminderStatus::Sent->value,
                'already_acked' => true,
            ];
        }

        return [
            'reminder_id' => (int) $reminder->id,
            'status' => ReminderStatus::Sent->value,
            'already_acked' => false,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Reminder>  $rows
     * @return array<string, Model>
     */
    private function loadSources($rows): array
    {
        $loaded = [];

        foreach ($rows->groupBy('notifiable_type') as $type => $group) {
            if (! is_string($type) || ! class_exists($type)) {
                continue;
            }

            $models = $type::query()
                ->whereIn('id', $group->pluck('notifiable_id')->unique()->all())
                ->with($this->recipients->eagerLoads($type))
                ->get();

            foreach ($models as $model) {
                $loaded[ReminderSource::key($type, $model->getKey())] = $model;
            }
        }

        return $loaded;
    }

    /**
     * @param  list<int>  $reminderIds
     * @return list<int>
     */
    private function ackedIds(User $user, array $reminderIds): array
    {
        return ReminderDelivery::query()
            ->whereIn('reminder_id', $reminderIds)
            ->where('user_id', $user->id)
            ->where('channel', self::CHANNEL->value)
            ->where('target_key', self::TARGET_KEY)
            ->pluck('reminder_id')
            ->map(fn (int $id): int => (int) $id)
            ->values()
            ->all();
    }
}
