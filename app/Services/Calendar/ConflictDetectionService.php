<?php

namespace App\Services\Calendar;

use App\Enums\SessionStatus;
use App\Models\CalendarEvent;
use App\Models\LiveSession;
use App\Models\PathStep;
use App\Models\PersonalTask;
use App\Support\CalendarTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * كشف التعارض ومَدار التوافر. القاعدة الوحيدة للنوافذ نصف-مفتوحة [start, end) في
 * App\Support\CalendarTime::whereOverlaps، وتستخدمها النماذج والخدمة معًا — لا صياغة ثانية لها هنا.
 *
 * العنصر بلا نهاية (جلسة لم يُخطَّط لها end، أو due_at واجب/مهمة) لحظة: لا يمتد ولا يبتلع النافذة،
 * فلا نختلق مدة تُنتج تعارضًا وهميًا يرفض به الأدمن حجزًا سليمًا.
 */
class ConflictDetectionService
{
    /** @return list<string> ما يثقل جدول المريض: غير ذلك إما لم يحدث أو انتهى */
    public const BLOCKING_STATUSES = [
        SessionStatus::Scheduled->value,
        SessionStatus::Confirmed->value,
        SessionStatus::Live->value,
    ];

    /**
     * @param  list<int>  $patientIds
     * @return Collection<int, LiveSession>
     */
    public function sessionConflictsForPatients(array $patientIds, CarbonImmutable $start, ?CarbonImmutable $end = null, ?int $excludeSessionId = null): Collection
    {
        if ($patientIds === []) {
            return collect();
        }

        return $this->sessionQuery($start, $end, $excludeSessionId)
            ->where(function ($q) use ($patientIds): void {
                $q->whereIn('patient_id', $patientIds)
                    ->orWhereHas('invites', fn ($i) => $i->whereIn('patient_id', $patientIds));
            })
            ->with(['invites.patient:id,name', 'admin:id,name'])
            ->get();
    }

    /** @return Collection<int, LiveSession> */
    public function therapistConflicts(int $adminId, CarbonImmutable $start, ?CarbonImmutable $end = null, ?int $excludeSessionId = null): Collection
    {
        return $this->sessionQuery($start, $end, $excludeSessionId)->where('admin_id', $adminId)->get();
    }

    /**
     * مشاغل المريض ثم الفواصل الخالية داخل [from, to) — نفس النوافذ التي يراها التقويم، فلا
     * يجيب endpoint «متاح» بينما يعرض التقويم شيئًا في نفس الموضع.
     *
     * الفواصل تُبنى من العناصر ذات المدّة (جلسة أو حدث بنهاية) وحدها: واجب أو مهمة بلا نهاية
     * يحتلان لحظة واحدة، وتقسيم الفاصل حول لحظة صفرية يعطي فجوتين متجاورتين توهم العميل بأن
     * الجدول مكسور. اللحظة تبقى معروضة في busy حتى يراها الأدمن وهو يختار.
     *
     * @return array{busy: list<array<string, mixed>>, free: list<array<string, mixed>>}
     */
    public function availability(int $patientId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $busy = $this->blockingSessions($patientId, $from, $to)
            ->merge($this->dueSteps($patientId, $from, $to))
            ->merge($this->dueTasks($patientId, $from, $to))
            ->merge($this->timedEvents($patientId, $from, $to))
            ->sortBy('starts_at')
            ->values();

        $spanning = $busy->filter(fn (array $block): bool => $block['ends_at'] !== null);

        return ['busy' => $busy->all(), 'free' => $this->gaps($spanning, $from, $to)];
    }

    private function sessionQuery(CarbonImmutable $start, ?CarbonImmutable $end, ?int $excludeSessionId)
    {
        return LiveSession::query()
            ->whereIn('status', self::BLOCKING_STATUSES)
            // لحظة بعرض ثانية واحدة: الأعمدة DATETIME لا تخزّن الكسور، فالفارق الأصغر من ثانية
            // يُقتطع عند الربط إلى «نفس اللحظة» ويصبح النطاق نصف-مفتوح فارغًا لا يتقاطع مع شيء.
            ->scheduledInRange($start, $end ?? $start->addSecond())
            ->when($excludeSessionId !== null, fn ($q) => $q->whereKeyNot($excludeSessionId));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function blockingSessions(int $patientId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return LiveSession::query()
            ->visibleToPatient($patientId)
            ->whereIn('status', self::BLOCKING_STATUSES)
            ->scheduledInRange($from, $to)
            ->get()
            ->map(fn (LiveSession $s): array => [
                'source' => 'live_session',
                'id' => $s->id,
                'title' => $s->title,
                'starts_at' => $s->scheduled_at?->setTimezone('UTC')->toIso8601String(),
                'ends_at' => $s->ends_at?->setTimezone('UTC')->toIso8601String(),
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function dueSteps(int $patientId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return PathStep::query()
            ->whereHas('path', fn ($q) => $q->where('patient_id', $patientId))
            ->dueInRange($from, $to)
            ->whereNotIn('status', ['completed'])
            ->get()
            ->map(fn (PathStep $s): array => [
                'source' => 'path_step',
                'id' => $s->id,
                'title' => $s->title,
                'starts_at' => $s->due_at?->setTimezone('UTC')->toIso8601String(),
                'ends_at' => null,
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function dueTasks(int $patientId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return PersonalTask::query()
            ->where('user_id', $patientId)
            ->inRange($from, $to)
            ->where('status', '!=', 'completed')
            ->get()
            ->map(fn (PersonalTask $t): array => [
                'source' => 'personal_task',
                'id' => $t->id,
                'title' => $t->title,
                'starts_at' => $t->due_at?->setTimezone('UTC')->toIso8601String(),
                'ends_at' => null,
            ]);
    }

    /** الأحداث طوال اليوم تُترك للتقويم: تملأ يومًا كاملًا فتُصير كل حجز «تعارضًا» بلا معنى. */
    private function timedEvents(int $patientId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return CalendarEvent::query()
            ->where('user_id', $patientId)
            ->inRange($from, $to)
            ->where('all_day', false)
            ->get()
            ->map(fn (CalendarEvent $e): array => [
                'source' => 'calendar_event',
                'id' => $e->id,
                'title' => $e->title,
                'starts_at' => $e->starts_at?->setTimezone('UTC')->toIso8601String(),
                'ends_at' => $e->ends_at?->setTimezone('UTC')->toIso8601String(),
            ]);
    }

    /**
     * دمج النوافذ ثم عكس الفواصل، كلها بـ UTC ونصف-مفتوحة. المدخل هنا عناصر ذات نهاية فعلًا.
     *
     * @param  Collection<int, array<string, mixed>>  $busy
     * @return list<array{starts_at: string, ends_at: string}>
     */
    private function gaps(Collection $busy, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $windows = $busy
            ->map(fn (array $block): array => [
                CalendarTime::toUtc($block['starts_at'], 'UTC'),
                CalendarTime::toUtc($block['ends_at'], 'UTC'),
            ])
            ->sortBy(fn (array $w): int => $w[0]->getTimestamp())
            ->values();

        $merged = [];

        foreach ($windows as $window) {
            $lastIndex = count($merged) - 1;

            if ($lastIndex >= 0 && $window[0]->lessThanOrEqualTo($merged[$lastIndex][1])) {
                if ($window[1]->greaterThan($merged[$lastIndex][1])) {
                    $merged[$lastIndex][1] = $window[1];
                }

                continue;
            }

            $merged[] = $window;
        }

        $gaps = [];
        $cursor = $from;

        foreach ($merged as [$start, $end]) {
            if ($start->gt($cursor)) {
                $gaps[] = $this->iso($cursor, $start);
            }

            $cursor = $end->gt($cursor) ? $end : $cursor;
        }

        if ($cursor->lt($to)) {
            $gaps[] = $this->iso($cursor, $to);
        }

        return $gaps;
    }

    /** @return array{starts_at: string, ends_at: string} */
    private function iso(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return ['starts_at' => $from->toIso8601String(), 'ends_at' => $to->toIso8601String()];
    }
}
