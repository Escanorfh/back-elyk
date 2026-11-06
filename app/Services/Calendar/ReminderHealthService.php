<?php

namespace App\Services\Calendar;

use App\Enums\ReminderStatus;
use App\Enums\SessionStatus;
use App\Models\AppSetting;
use App\Models\LiveSession;
use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\SchedulerRun;
use App\Support\Reminders\ReminderSettings;
use Carbon\CarbonImmutable;

/**
 * نظرة المشغِّل: هل الجدول حيّ، وكم تأخّر، وما العالق — بأرقام فقط.
 *
 * لا نصّ خطأ ولا عنوان مريض ولا مفتاح: last_error قد يحمل رسالة استثناء من مكتبة، وشاشات الصحة
 * تُقرأ من أكثر من مكان، فالاختصار هنا ليس زهدًا بل حدود ما يُسمح بخروجه من قاعدة البيانات.
 *
 * الاستعلامات محدودة عمدًا: تجميعية واحدة على فهرس الحالة، وعدّ على (status, reminds_at)، وعشرة
 * صفوف من scheduler_runs بترتيب run_at المفهرس. لا مسح كامل لكل طلب صحة.
 */
final class ReminderHealthService
{
    private const RECENT_RUNS = 10;

    /**
     * @return array<string, mixed>
     */
    public function snapshot(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $settings = ReminderSettings::load();

        return [
            'server_time' => $now->toIso8601String(),
            'scheduler' => $this->scheduler($now),
            'reminders' => $this->reminders($now, $settings),
            'deliveries' => $this->deliveries($now),
            'retention' => $this->retention($now, $settings),
            'missed_sessions' => $this->missedSessions($now, $settings),
            'recent_runs' => $this->recentRuns(),
        ];
    }

    /**
     * lag هو السؤال الوحيد الذي يجيب عن «هل cron ميت؟»: آخر دورة قُفلَت فعلًا، لا آخر محاولة.
     * دورة لم تُقفل (finished_at null) هي crash، وعدّها أعلى من صفر يعني أن نداءات تتقطّع.
     *
     * @return array<string, mixed>
     */
    private function scheduler(CarbonImmutable $now): array
    {
        $last = SchedulerRun::query()->latestFirst()->first();
        $lastFinished = SchedulerRun::query()->whereNotNull('finished_at')->latestFirst()->first();

        return [
            'last_run_at' => $last?->run_at?->toIso8601String(),
            'last_finished_at' => $lastFinished?->finished_at?->toIso8601String(),
            // الاتجاه من آخر دورة إلى الآن: Carbon 3 يوقّع الفرق بالعكس، فالسالب هنا يعني متأخّرًا.
            'lag_seconds' => $lastFinished?->run_at === null
                ? null
                : (int) $lastFinished->run_at->diffInSeconds($now, false),
            'crashed_unfinished' => SchedulerRun::query()->whereNull('finished_at')->count(),
            'batch_id' => $last?->batch_id,
            'last_due_considered' => (int) ($last?->due_considered ?? 0),
            'last_sent' => (int) ($last?->sent ?? 0),
            'last_failed' => (int) ($last?->failed ?? 0),
            'last_max_lateness_seconds' => $last?->max_lateness_seconds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reminders(CarbonImmutable $now, ReminderSettings $settings): array
    {
        $counts = Reminder::query()
            ->selectRaw('status, count(*) as aggregate')
            ->whereIn('status', [
                ReminderStatus::Pending->value,
                ReminderStatus::Sending->value,
                ReminderStatus::Failed->value,
            ])
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        // backlog الحقيقي: pending حان ميعادها ولم تُرسل — لا يكفي «كم pending» وهي مستقبلًا.
        $oldest = Reminder::query()
            ->where('status', ReminderStatus::Pending->value)
            ->where('reminds_at', '<=', $now)
            ->min('reminds_at');

        return [
            'pending' => (int) ($counts[ReminderStatus::Pending->value] ?? 0),
            'pending_overdue' => Reminder::query()
                ->where('status', ReminderStatus::Pending->value)
                ->where('reminds_at', '<=', $now)
                ->count(),
            'oldest_pending_overdue_at' => $oldest === null
                ? null
                : CarbonImmutable::parse((string) $oldest)->setTimezone('UTC')->toIso8601String(),
            'sending' => (int) ($counts[ReminderStatus::Sending->value] ?? 0),
            'failed' => (int) ($counts[ReminderStatus::Failed->value] ?? 0),
            'exhausted' => Reminder::query()
                ->where('status', ReminderStatus::Failed->value)
                ->where('attempts', '>=', $settings->retryMaxAttempts())
                ->count(),
            'expired_claims' => Reminder::query()
                ->where('status', ReminderStatus::Sending->value)
                ->where('claimed_at', '<', $now->subMinutes($settings->claimLeaseMinutes()))
                ->count(),
            'batch_size' => $settings->batchSize(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveries(CarbonImmutable $now): array
    {
        $counts = ReminderDelivery::query()
            ->selectRaw('status, count(*) as aggregate')
            ->whereIn('status', [
                ReminderStatus::Pending->value,
                ReminderStatus::Failed->value,
                ReminderStatus::Skipped->value,
            ])
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        return [
            'pending' => (int) ($counts[ReminderStatus::Pending->value] ?? 0),
            'failed' => (int) ($counts[ReminderStatus::Failed->value] ?? 0),
            'skipped' => (int) ($counts[ReminderStatus::Skipped->value] ?? 0),
            'retryable' => ReminderDelivery::query()->retryable($now)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function retention(CarbonImmutable $now, ReminderSettings $settings): array
    {
        $state = (string) (AppSetting::query()->where('key', ReminderSettings::TRIM_KEY)->value('value') ?? '');

        return [
            'state' => $state === '' ? 'missing' : $state,
            'trimmed_on' => str_starts_with($state, 'idle:') ? substr($state, 5) : null,
            // running:<token> حديثة = تقليم يعمل الآن؛ القديمة = إيجار ميت ستستردّه دورة قادمة.
            'lease_active' => str_starts_with($state, 'running:')
                && AppSetting::query()
                    ->where('key', ReminderSettings::TRIM_KEY)
                    ->where('updated_at', '>=', $now->subMinutes($settings->claimLeaseMinutes()))
                    ->exists(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function missedSessions(CarbonImmutable $now, ReminderSettings $settings): array
    {
        return [
            'gate_open' => (bool) AppSetting::getValue('calendar.status_widening_enabled', false),
            // كم ينتظر خلف البوابة: لا كتابة هنا، فقرار فتحها لصاحب المشروع لا للمراقبة.
            'past_grace_candidates' => LiveSession::query()
                ->whereIn('status', SessionStatus::BOOKED)
                ->whereNotNull('scheduled_at')
                ->where('scheduled_at', '<=', $now->subMinutes($settings->missedGraceMinutes()))
                ->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentRuns(): array
    {
        return SchedulerRun::query()
            ->latestFirst()
            ->limit(self::RECENT_RUNS)
            ->get(['run_at', 'finished_at', 'due_considered', 'sent', 'failed', 'max_lateness_seconds'])
            ->map(fn (SchedulerRun $run): array => [
                'run_at' => $run->run_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'due_considered' => (int) $run->due_considered,
                'sent' => (int) $run->sent,
                'failed' => (int) $run->failed,
                'max_lateness_seconds' => $run->max_lateness_seconds === null ? null : (int) $run->max_lateness_seconds,
            ])
            ->all();
    }
}
