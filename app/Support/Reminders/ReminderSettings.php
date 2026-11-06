<?php

namespace App\Support\Reminders;

use App\Models\AppSetting;

/**
 * إعدادات reminders.* تُقرأ مرة واحدة لكل دورة مُشغِّل، وتسقط إلى نفس الافتراضات المكتوبة في الكود:
 * صفوف app_settings مزروعة لكنها قد تُمحى يدويًا، ومسار التذكير لا يجوز أن يتعطّل لأن صفًّا غاب.
 *
 * الحدود الدنيا ليست تجميلًا. batch_size=0 يجعل كل دورة بلا عمل، وretry_max_attempts=0 يمنع أي
 * إعادة محاولة، والاثنان يُتركان سجلّ scheduler_runs يقرأ «ناجح» بينما لم يُرسَل شيء قط.
 *
 * scheduler_token لا يُخزَّن أبدًا كما كُتب: القيمة هي sha256 للسرّ، فمسار يقرأ app_settings لا
 * يكشف سرّ النبضات، والمقارنة في الوسيط تُتمّ بـ hash_equals على بصمة ما أُرسِل لا على النص.
 */
final class ReminderSettings
{
    public const GROUP = 'reminders';

    public const TOKEN_KEY = 'reminders.scheduler_token';

    public const TRIM_KEY = 'reminders.trim_state';

    private const DEFAULTS = [
        'missed_grace_minutes' => 30,
        'claim_lease_minutes' => 10,
        'batch_size' => 200,
        'retry_max_attempts' => 3,
        'scheduler_runs_retention_days' => 7,
        'deliveries_retention_days' => 90,
        'run_budget_seconds' => 20,
    ];

    /** @param  array<string, string>  $rows  مفهرس بمفتاح app_settings */
    private function __construct(private readonly array $rows) {}

    public static function load(): self
    {
        $rows = AppSetting::query()
            ->where('group', self::GROUP)
            ->pluck('value', 'key')
            ->map(fn ($value): string => (string) $value)
            ->all();

        return new self($rows);
    }

    /** كم ثانية تُقبل في الطلب الواحد قبل أن يُترك الباقي للدورة التالية. */
    public function runBudgetSeconds(): int
    {
        return $this->clamped('run_budget_seconds', 1, 55);
    }

    /** حجم الدفعة؛ السقف دون نطاق scan الذي يهدّد طلب Wasmer. */
    public function batchSize(): int
    {
        return $this->clamped('batch_size', 1, 500);
    }

    public function claimLeaseMinutes(): int
    {
        return $this->clamped('claim_lease_minutes', 1, 240);
    }

    /** attempts عمود tinyInteger، وسقف أعلى من تسعة يعني دورة أعوامًا قبل أن يستقرّ الفشل. */
    public function retryMaxAttempts(): int
    {
        return $this->clamped('retry_max_attempts', 1, 9);
    }

    public function missedGraceMinutes(): int
    {
        return $this->clamped('missed_grace_minutes', 1, 1440);
    }

    public function schedulerRunsRetentionDays(): int
    {
        return $this->clamped('scheduler_runs_retention_days', 1, 365);
    }

    public function deliveriesRetentionDays(): int
    {
        return $this->clamped('deliveries_retention_days', 7, 730);
    }

    public function schedulerTokenHash(): ?string
    {
        $stored = trim((string) ($this->rows[self::TOKEN_KEY] ?? ''));

        return $stored === '' ? null : $stored;
    }

    /** البصمة التي تُخزَّن وتُقارن؛ لا تُرجَع أبدًا إلى المخرج. */
    public static function fingerprint(string $secret): string
    {
        return hash('sha256', $secret);
    }

    private function clamped(string $name, int $min, int $max): int
    {
        $raw = $this->rows[self::GROUP.'.'.$name] ?? self::DEFAULTS[$name];

        return max($min, min($max, (int) $raw));
    }
}
