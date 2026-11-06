<?php

namespace App\Services\Calendar;

use App\Enums\ReminderChannel;
use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\User;
use App\Notifications\ReminderDueNotification;
use App\Support\ReminderSource;
use App\Support\Reminders\ReminderSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * المعالج الذي يستحق اسم «مُرسِل»: يطالب بالتذكيرات المستحقّة، ويوصلها في صندوق الوارد، ويكتب
 * سطر توزيع لكل مستقبِل، ويقرّر حالة التذكير بعدها. لا أحد قبله كان يقرأ scopeDue إطلاقًا.
 *
 * المطالبة CAS صافي: UPDATE … WHERE status='pending' على صف واحد، فالعدد المُصاب 1 يعني أننا
 * ملّاكه، ولو سبقنا طلبٌ متداخل صار نصيبه صفرًا وانتقل للصف التالي. لا عمود token في المخطط
 * (2026_11_04_000006:14-17 صمّم المطالبة على status + claimed_at)، ولا أعمدة تُخترع هنا.
 *
 * التكرار محروس ببابين لا بواحد: القيد الفريد (reminder_id,user_id,channel,target_key) يجعل
 * الإدراج والإرسال قرارًا واحدًا، وتسميم «أُرسلت فعلًا» يوقف المحاولة الثانية. أما المحاولة
 * المؤجَّلة فتُدار بـ next_retry_at على سطر التوزيع — لا بحرق محاولات التذكير كل دقيقة.
 */
final class ReminderDispatcher
{
    private const CHANNEL = ReminderChannel::InApp;

    private const TARGET_KEY = 'db';

    /** لا نصّ أطول من هذا في error/last_error: العمود text لكن الشاشة والسجلّ يقرآن المختصر. */
    private const ERROR_LIMIT = 180;

    public function __construct(private readonly RecipientResolver $recipients) {}

    /**
     * @return array{considered: int, sent: int, failed: int, skipped: int, cancelled: int, released: int, max_lateness_seconds: int|null}
     */
    public function dispatch(CarbonImmutable $now, ReminderSettings $settings, int $deadlineNs): array
    {
        $tally = [
            'considered' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'cancelled' => 0,
            'released' => 0,
            'max_lateness_seconds' => null,
        ];

        $candidates = $this->candidates($now, $settings);

        if ($candidates->isEmpty()) {
            return $tally;
        }

        $sources = $this->loadSources($candidates);

        // مستقبِلون وسطور توزيع دفعة واحدة: لا استعلام لكل تذكير ولا لكل شخص.
        $people = $this->loadPeople($candidates, $sources);
        $deliveries = $this->loadDeliveries($candidates->modelKeys());

        foreach ($candidates as $reminder) {
            if (hrtime(true) > $deadlineNs) {
                break;
            }

            $tally['considered']++;

            if (! $this->claim($reminder, $now)) {
                continue;
            }

            // Carbon 3 يوقّع الفرق «الطابع - المستقبِل»؛ التأخّر هو reminds_at → now لا العكس.
            $lateness = max(0, (int) $reminder->reminds_at->diffInSeconds($now, false));

            if ($tally['max_lateness_seconds'] === null || $lateness > $tally['max_lateness_seconds']) {
                $tally['max_lateness_seconds'] = (int) $lateness;
            }

            $outcome = $this->deliver($reminder, $sources, $people, $deliveries, $now, $settings);

            $tally[$outcome]++;
        }

        return $tally;
    }

    /**
     * استرداد مطالبات ميتة: دورة-crashed تركت الصفوف sending فلن يلمسها أحد أبدًا لو تُركت.
     * المحاولة تُحسب هنا، وإلا دارت حلقة لا تنتهي بين موت الطلب وإعادة المطالبة.
     *
     * @return int عدد الصفوف المُرجَعة
     */
    public function releaseExpiredClaims(CarbonImmutable $now, ReminderSettings $settings): int
    {
        $staleBefore = $now->subMinutes($settings->claimLeaseMinutes());

        $ids = Reminder::query()
            ->where('status', ReminderStatus::Sending->value)
            ->where('claimed_at', '<', $staleBefore)
            ->orderBy('claimed_at')
            ->limit($settings->batchSize())
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        return Reminder::query()
            ->whereKey($ids)
            ->where('status', ReminderStatus::Sending->value)
            ->where('claimed_at', '<', $staleBefore)
            ->update([
                'status' => ReminderStatus::Pending->value,
                'claimed_at' => null,
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => $this->error('claim lease expired'),
            ]);
    }

    /**
     * المستحقّة فقط: pending حان ميعادها، أو failed لم يستنفد المحاولات — وهي مرتّبة بالأقدم
     * حتى لا تجوع تذكيرات قديمة بينما تُولد جديدة كل دقيقة.
     *
     * @return Collection<int, Reminder>
     */
    private function candidates(CarbonImmutable $now, ReminderSettings $settings): Collection
    {
        return Reminder::query()
            ->where('reminds_at', '<=', $now)
            ->where(fn ($query) => $query
                ->where('status', ReminderStatus::Pending->value)
                ->orWhere(fn ($retry) => $retry
                    ->where('status', ReminderStatus::Failed->value)
                    ->where('attempts', '<', $settings->retryMaxAttempts())
                )
            )
            ->orderBy('reminds_at')
            ->limit($settings->batchSize())
            ->get();
    }

    private function claim(Reminder $reminder, CarbonImmutable $now): bool
    {
        $claimed = Reminder::query()
            ->whereKey($reminder->id)
            ->where('status', $reminder->status)
            ->update(['status' => ReminderStatus::Sending->value, 'claimed_at' => $now]);

        return $claimed === 1;
    }

    /**
     * @param  array<string, Model>  $sources  مفهرس بالفئة والمعرّف: App\Models\LiveSession#7 غير App\Models\PathStep#7
     * @param  array<int, User>  $people
     * @param  array<string, ReminderDelivery>  $deliveries
     */
    private function deliver(
        Reminder $reminder,
        array $sources,
        array $people,
        array $deliveries,
        CarbonImmutable $now,
        ReminderSettings $settings
    ): string {
        $source = $sources[ReminderSource::key($reminder->notifiable_type, $reminder->notifiable_id)] ?? null;

        if ($source === null) {
            return $this->settle($reminder, ReminderStatus::Cancelled, 'source deleted');
        }

        if (! $this->recipients->isSourceOpen($source)) {
            return $this->settle($reminder, ReminderStatus::Cancelled, 'source closed');
        }

        $ids = $this->recipients->recipientIds($reminder);

        if ($ids === []) {
            return $this->settle($reminder, ReminderStatus::Skipped, 'no recipient');
        }

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        $deferred = 0;
        $attempted = 0;

        foreach ($ids as $userId) {
            $user = $people[$userId] ?? null;

            if ($user === null) {
                $skipped++;

                continue;
            }

            $reason = $this->recipients->skipReason($user, $reminder, self::CHANNEL->value);

            if ($reason !== null) {
                $this->mark($this->row($reminder, $user, $deliveries), ReminderStatus::Skipped, $reason);
                $skipped++;

                continue;
            }

            $row = $this->row($reminder, $user, $deliveries);

            if ($row->status === ReminderStatus::Sent->value) {
                $sent++;

                continue;
            }

            if ($row->next_retry_at !== null && $row->next_retry_at->gt($now)) {
                $deferred++;

                continue;
            }

            $attempted++;

            try {
                $user->notify(new ReminderDueNotification($reminder, $source));
                $this->mark($row, ReminderStatus::Sent, null, $now);
                $sent++;
            } catch (Throwable $e) {
                $this->mark($row, ReminderStatus::Failed, $this->error($e->getMessage()), null, $now, $settings);
                $failed++;
            }
        }

        // لم يُحاول شيء والكل مُنتظر: نعيد الصف pending بلا حرق محاولات، والدورة التالية تعود إليه.
        if ($attempted === 0 && $deferred > 0) {
            return $this->release($reminder);
        }

        if ($failed > 0) {
            return $this->settle($reminder, ReminderStatus::Failed, 'delivery failed', $attempted);
        }

        if ($sent > 0) {
            return $this->settle($reminder, ReminderStatus::Sent, null, $attempted, $now);
        }

        return $this->settle($reminder, ReminderStatus::Skipped, 'all recipients filtered', $attempted);
    }

    /**
     * @param  Collection<int, Reminder>  $candidates
     * @return array<string, Model>  مفهرس بالنوع والمعرّف، فالأنواع الأربعة تتشارك تسلسل المعرّفات
     */
    private function loadSources(Collection $candidates): array
    {
        $grouped = $candidates->groupBy('notifiable_type');
        $loaded = [];

        foreach ($grouped as $type => $rows) {
            if (! is_string($type) || ! class_exists($type)) {
                continue;
            }

            $models = $type::query()
                ->whereIn('id', $rows->pluck('notifiable_id')->unique()->all())
                ->with($this->recipients->eagerLoads($type))
                ->get();

            foreach ($models as $model) {
                $loaded[ReminderSource::key($type, $model->getKey())] = $model;
            }
        }

        return $loaded;
    }

    /**
     * الحالة النهائية للتذكير، بشروطه هو: writing على sending الذي نملكه في هذه الدورة وحدها،
     * ومحاولات لا تزيد إلا إذا زُجّت رسالة فعلًا — فالإرجاع المؤقّت ليس فشلًا ولا يُحسب واحدًا.
     */
    private function settle(
        Reminder $reminder,
        ReminderStatus $status,
        ?string $error = null,
        int $attempted = 0,
        ?CarbonImmutable $sentAt = null
    ): string {
        $payload = [
            'status' => $status->value,
            'sent_at' => $sentAt,
            'last_error' => $error === null ? null : $this->error($error),
        ];

        if ($attempted > 0) {
            $payload['attempts'] = DB::raw('attempts + 1');
        }

        Reminder::query()
            ->whereKey($reminder->id)
            ->where('status', ReminderStatus::Sending->value)
            ->update($payload);

        return $status->value;
    }

    private function release(Reminder $reminder): string
    {
        Reminder::query()
            ->whereKey($reminder->id)
            ->where('status', ReminderStatus::Sending->value)
            ->update(['status' => ReminderStatus::Pending->value, 'claimed_at' => null]);

        return 'released';
    }

    /**
     * كل المستقبِلين مع إعداداتهم في استعلام واحد، بعد تثبيت علاقة المصدر على كل تذكير —
     * فلا يستعلم resolve عن مصدره ولا عن تفضيلات صاحبه مرة أخرى عند الإرسال.
     *
     * @param  array<string, Model>  $sources
     * @return array<int, User>
     */
    private function loadPeople(Collection $candidates, array $sources): array
    {
        $ids = [];

        foreach ($candidates as $reminder) {
            $source = $sources[ReminderSource::key($reminder->notifiable_type, $reminder->notifiable_id)] ?? null;

            if ($source === null) {
                continue;
            }

            $reminder->setRelation('notifiable', $source);

            foreach ($this->recipients->recipientIds($reminder) as $userId) {
                $ids[$userId] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', array_keys($ids))
            ->with('settings')
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * @return array<string, ReminderDelivery>
     */
    private function loadDeliveries(array $reminderIds): array
    {
        return ReminderDelivery::query()
            ->whereIn('reminder_id', $reminderIds)
            ->where('channel', self::CHANNEL->value)
            ->get()
            ->mapWithKeys(fn (ReminderDelivery $row): array => [$row->reminder_id.':'.$row->user_id => $row])
            ->all();
    }

    /**
     * @param  array<string, ReminderDelivery>  $deliveries
     */
    private function row(Reminder $reminder, User $user, array &$deliveries): ReminderDelivery
    {
        $key = $reminder->id.':'.$user->id;

        if (isset($deliveries[$key])) {
            return $deliveries[$key];
        }

        $row = new ReminderDelivery([
            'reminder_id' => $reminder->id,
            'user_id' => $user->id,
            'channel' => self::CHANNEL->value,
            'target_key' => self::TARGET_KEY,
            'status' => ReminderStatus::Pending->value,
        ]);

        try {
            $row->save();
        } catch (Throwable) {
            // سبقنا طلب متداخل بنفس السطر: القيد الفريد هو الحكم، فنعيد القراءة لا المحاولة.
            $row = ReminderDelivery::query()
                ->where('reminder_id', $reminder->id)
                ->where('user_id', $user->id)
                ->where('channel', self::CHANNEL->value)
                ->where('target_key', self::TARGET_KEY)
                ->firstOrFail();
        }

        $deliveries[$key] = $row;

        return $row;
    }

    /**
     * سطر التوزيع وحالته. فشلٌ واحد لا يمحو sent_at السابق ولا يجعله sent بالخطأ.
     */
    private function mark(
        ReminderDelivery $row,
        ReminderStatus $status,
        ?string $error = null,
        ?CarbonImmutable $sentAt = null,
        ?CarbonImmutable $now = null,
        ?ReminderSettings $settings = null
    ): void {
        $attempts = (int) $row->attempts;
        $nextRetryAt = null;

        if ($status === ReminderStatus::Failed && $now !== null && $settings !== null) {
            $attempts++;

            // إعادة المحاولة بسلم مُرجَّح لا بموجة كل دقيقة: attempts عمود tinyInteger والسقف إعداد.
            if ($attempts < $settings->retryMaxAttempts()) {
                $nextRetryAt = $now->addMinutes($settings->claimLeaseMinutes() * $attempts);
            }
        }

        $row->update([
            'status' => $status->value,
            'error' => $error === null ? null : $this->error($error),
            'attempts' => $attempts,
            'next_retry_at' => $nextRetryAt,
            'attempted_at' => $now ?? $row->attempted_at,
            'sent_at' => $sentAt,
        ]);
    }

    private function error(string $message): string
    {
        return mb_substr(trim($message), 0, self::ERROR_LIMIT);
    }
}
