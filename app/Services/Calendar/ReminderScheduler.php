<?php

namespace App\Services\Calendar;

use App\Models\SchedulerRun;
use App\Support\Reminders\ReminderSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * دورة واحدة = نداء cron واحد. هذا هو البديل الوحيد الممكن عن schedule:work على Wasmer:
 * لا عملية مقيمة، فالطلب نفسه هو الجدول، والزمن المتبقي داخل الطلب هو سقف العمل.
 *
 * الترتيب ثابت: استرداد المطالبات الميتة ← الإرسال ← الجلسات الفائتة ← التقليم. استرداد الإيجارات
 * أولًا لأن صفوف sending اليتيمة تُصلحها ثانية واحدة وتحرّّر مستقبِليها، والتقليم آخرًا لأنه وحده
 * يستهلك وقتًا دون أن يُنجز مستحقًا.
 *
 * صفّ scheduler_runs يُكتب قبل أي عمل ويُقفَل بـ finished_at بعد النجاح فقط. لا عمود error في
 * المخطط ولا أعمدة تُخترع: دورةٌ لم تُقفل هي دورةٌ فشلت، وهذا وحده ما تحتاجه المراقبة لتعرفه.
 */
final class ReminderScheduler
{
    public function __construct(
        private readonly ReminderDispatcher $dispatcher,
        private readonly MissedSessionService $missed,
        private readonly ReminderRetentionService $retention,
    ) {}

    /**
     * @return array{batch_id: string, due_considered: int, sent: int, failed: int, skipped: int,
     *               cancelled: int, released: int, claims_recovered: int, max_lateness_seconds: int|null,
     *               missed: array{considered: int, marked: int, blocked: int},
     *               retention: array{acquired: bool, reason: string|null, runs_deleted: int, deliveries_deleted: int},
     *               budget_seconds: int, budget_exhausted: bool, duration_ms: int}
     */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');

        $settings = ReminderSettings::load();

        // batch_id هو الخيط بين السجلّ والسطر في log، فالجدول نفسه بلا عمود خطأ.
        $batchId = (string) Str::uuid();

        $run = SchedulerRun::query()->create(['run_at' => $now, 'batch_id' => $batchId]);

        $startedNs = hrtime(true);
        $deadlineNs = $startedNs + ($settings->runBudgetSeconds() * 1_000_000_000);

        $tally = $this->emptyTally();
        $claimsRecovered = 0;
        $missedTally = ['considered' => 0, 'marked' => 0, 'blocked' => 0];
        $retentionTally = ['acquired' => false, 'reason' => null, 'runs_deleted' => 0, 'deliveries_deleted' => 0];
        $stage = 'claims';

        try {
            $claimsRecovered = $this->dispatcher->releaseExpiredClaims($now, $settings);

            $stage = 'dispatch';
            $tally = $this->dispatcher->dispatch($now, $settings, $deadlineNs);

            $stage = 'missed';
            if (! $this->outOfBudget($deadlineNs)) {
                $missedTally = $this->missed->pass($now, $settings);
            }

            $stage = 'retention';
            if (! $this->outOfBudget($deadlineNs)) {
                $retentionTally = $this->retention->run($now, $settings);
            }
        } catch (Throwable $e) {
            // الأرقام المرصودة تُكتب ثم يُعاد الرمي: سجلٌّ بنصف الحقيقة خير من سجلٍّ بمحطة بيضاء،
            // وfinished_at الباقي null هو بالضبط ما تقرأه المراقبة كدورة فاشلة.
            $this->record($run, $tally);

            Log::error('reminder scheduler failed', [
                'batch_id' => $batchId,
                'stage' => $stage,
                'exception' => $e::class,
                'message' => mb_substr($e->getMessage(), 0, 180),
            ]);

            throw $e;
        }

        $this->record($run, $tally, $now);

        $elapsedMs = (int) round((hrtime(true) - $startedNs) / 1_000_000);
        $budgetSeconds = $settings->runBudgetSeconds();

        return [
            'batch_id' => $batchId,
            'due_considered' => $tally['considered'],
            'sent' => $tally['sent'],
            'failed' => $tally['failed'],
            'skipped' => $tally['skipped'],
            'cancelled' => $tally['cancelled'],
            'released' => $tally['released'],
            'claims_recovered' => $claimsRecovered,
            'max_lateness_seconds' => $tally['max_lateness_seconds'],
            'missed' => $missedTally,
            'retention' => $retentionTally,
            'budget_seconds' => $budgetSeconds,
            'budget_exhausted' => $elapsedMs >= ($budgetSeconds * 1000),
            'duration_ms' => $elapsedMs,
        ];
    }

    /**
     * @param  array{considered: int, sent: int, failed: int, max_lateness_seconds: int|null}  $tally
     */
    private function record(SchedulerRun $run, array $tally, ?CarbonImmutable $finishedAt = null): void
    {
        $run->update([
            'due_considered' => $tally['considered'],
            'sent' => $tally['sent'],
            'failed' => $tally['failed'],
            'max_lateness_seconds' => $tally['max_lateness_seconds'],
            'finished_at' => $finishedAt,
        ]);
    }

    /** الميزانية تُقاس بناسخة صارفة لا بثانية الطلب: WASI يقطع الاتصال بصمت عند السقف. */
    private function outOfBudget(int $deadlineNs): bool
    {
        return hrtime(true) >= $deadlineNs;
    }

    /**
     * @return array{considered: int, sent: int, failed: int, skipped: int, cancelled: int, released: int, max_lateness_seconds: int|null}
     */
    private function emptyTally(): array
    {
        return [
            'considered' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'cancelled' => 0,
            'released' => 0,
            'max_lateness_seconds' => null,
        ];
    }
}
