<?php

namespace App\Services\Calendar;

use App\Enums\ReminderStatus;
use App\Models\AppSetting;
use App\Models\ReminderDelivery;
use App\Models\SchedulerRun;
use App\Support\Reminders\ReminderSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * الاحتفاظ المتفق عليه: scheduler_runs سبعة أيام وreminder_deliveries تسعون، بحذف مُقيَّد.
 *
 * القيمة المُرجَّحة في app_settings.reminders.trim_state هي العقد كله، بصيغ ثلاث:
 *   idle                      لم يُقلَع شيء بعد
 *   idle:YYYY-MM-DD           آخر يوم اكتمل فيه التقليم — فهي بوابة «مرة كل يوم» بلا عمود إضافي
 *   running:<token>           دورة تعمل الآن، وupdated_at هي مدة إيجارها
 *
 * القلع CAS على القيمة المرصودة لا على «اكتب ثم اقرأ»: طلبان متداخلان يقرأان idle، ومن يكتب أولًا
 * يفوز والآخر يرجع بلا عمل — فلا تقليم مزدوج ولا حاجة إلى جدول إيجارات.
 *
 * لا «اكتمل» قبل اكتمال الحذف: الصفة تُكتب في آخر تحديث ناجح فقط، ولو مات الطلب في الوسط يبقى
 * running:<token> حتى تنتهي مدة الإيجار فتستردّه دورة تالية. هذا هو الفرق بين سجلّ نظيف وسجلّ يكذب.
 */
final class ReminderRetentionService
{
    /** حدّ المتن: ألفا صفّ كحدّ أقصى للدفعة وخمس دفعات للدورة — لا حذف مفتوح داخل طلب HTTP واحد. */
    private const ROWS_PER_BATCH = 2000;

    private const MAX_BATCHES_PER_RUN = 5;

    /** أعلى ما يُقرأ من المعرّفات في الدورة، فلا list في الذاكرة بلا سقف. */
    private const MAX_ROWS_PER_TABLE = self::ROWS_PER_BATCH * self::MAX_BATCHES_PER_RUN;

    /**
     * @return array{acquired: bool, reason: string|null, runs_deleted: int, deliveries_deleted: int}
     */
    public function run(CarbonImmutable $now, ReminderSettings $settings): array
    {
        $summary = ['acquired' => false, 'reason' => null, 'runs_deleted' => 0, 'deliveries_deleted' => 0];

        $lease = $this->acquire($now, $settings);

        if ($lease === null) {
            $current = $this->state();

            $summary['reason'] = $current !== null && str_starts_with($current, 'idle:'.$now->toDateString())
                ? 'already-trimmed-today'
                : 'lease-held';

            return $summary;
        }

        $summary['acquired'] = true;

        try {
            $summary['runs_deleted'] = $this->deleteInBatches(
                SchedulerRun::class,
                SchedulerRun::query()
                    ->where('run_at', '<', $now->subDays($settings->schedulerRunsRetentionDays()))
                    ->orderBy('id')
                    ->limit(self::MAX_ROWS_PER_TABLE)
                    ->pluck('id')
            );

            $summary['deliveries_deleted'] = $this->deleteInBatches(
                ReminderDelivery::class,
                ReminderDelivery::query()
                    ->where('created_at', '<', $now->subDays($settings->deliveriesRetentionDays()))
                    ->whereIn('status', [
                        ReminderStatus::Sent->value,
                        ReminderStatus::Skipped->value,
                        ReminderStatus::Cancelled->value,
                    ])
                    // سطرٌ لتذكير لم يستقرّ بعد هو دليل إعادة المحاولة؛ حذفه يفتح باب إرسال قديم متأخر.
                    ->whereHas(
                        'reminder',
                        fn ($query) => $query->whereIn('status', [
                            ReminderStatus::Sent->value,
                            ReminderStatus::Skipped->value,
                            ReminderStatus::Cancelled->value,
                        ])
                    )
                    ->orderBy('id')
                    ->limit(self::MAX_ROWS_PER_TABLE)
                    ->pluck('id')
            );
        } catch (Throwable $e) {
            $this->release($lease, 'idle');

            throw $e;
        }

        $this->release($lease, 'idle:'.$now->toDateString());

        return $summary;
    }

    /**
     * @param  array{key: string, observed: string, token: string}  $lease
     */
    private function release(array $lease, string $value): void
    {
        AppSetting::query()
            ->where('key', $lease['key'])
            ->where('value', 'running:'.$lease['token'])
            ->update(['value' => $value]);
    }

    /**
     * @return array{key: string, observed: string, token: string}|null
     */
    private function acquire(CarbonImmutable $now, ReminderSettings $settings): ?array
    {
        $key = ReminderSettings::TRIM_KEY;
        $observed = $this->state();

        if ($observed === null) {
            // الصف مزروع في 4.1 لكنه قد يُمحى؛ نبنيه idle بدل أن نُفشل المسار كله.
            DB::table('app_settings')->insertOrIgnore([
                'key' => $key,
                'value' => 'idle',
                'type' => 'string',
                'group' => ReminderSettings::GROUP,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $observed = 'idle';
        }

        if (str_starts_with($observed, 'idle:')) {
            if (substr($observed, 5) === $now->toDateString()) {
                return null;
            }

            // أمس لا يعني اليوم: الصيغة القديمة تُقبل كـ idle وتُستبدل عند الاكتمال.
        }

        if (str_starts_with($observed, 'running:')) {
            $staleBefore = $now->subMinutes($settings->claimLeaseMinutes());

            $held = AppSetting::query()
                ->where('key', $key)
                ->where('value', $observed)
                ->where('updated_at', '>=', $staleBefore)
                ->exists();

            if ($held) {
                return null;
            }
        }

        $token = bin2hex(random_bytes(12));

        $won = AppSetting::query()
            ->where('key', $key)
            ->where('value', $observed)
            ->update(['value' => 'running:'.$token]);

        return $won === 1 ? ['key' => $key, 'observed' => $observed, 'token' => $token] : null;
    }

    private function state(): ?string
    {
        $value = AppSetting::query()->where('key', ReminderSettings::TRIM_KEY)->value('value');

        return $value === null ? null : (string) $value;
    }

    /**
     * تحديد المعرّفات ثم حذفها بالقائمة، لا حذف بـ where زمني عارم: الأقفى على الفهرس وأصغر قفلًا.
     *
     * الجدول يُعطى صراحةً: قائمة معرّفات scheduler_runs لا معنى لها داخل reminder_deliveries —
     * تسلسلان مختلفان وأرقام متكرّرة، والحذف المزدوج كان سيُفسد العدّادَين.
     *
     * @param  class-string  $model
     * @param  \Illuminate\Support\Collection<int, int>  $ids
     */
    private function deleteInBatches(string $model, $ids): int
    {
        $deleted = 0;
        $batches = 0;

        foreach ($ids->chunk(self::ROWS_PER_BATCH) as $chunk) {
            if ($batches >= self::MAX_BATCHES_PER_RUN) {
                break;
            }

            $deleted += $model::query()->whereKey($chunk->all())->delete();

            $batches++;
        }

        return $deleted;
    }
}
