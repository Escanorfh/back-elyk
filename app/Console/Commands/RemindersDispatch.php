<?php

namespace App\Console\Commands;

use App\Services\Calendar\ReminderScheduler;
use Illuminate\Console\Command;

/**
 * نفس الدورة خلف باب HTTP، لكن من الـ CLI: للتشغيل اليدوي وللتجربة المحلية حيث لا cron.
 *
 * لا منطق هنا إطلاقًا — المنطق كله في ReminderScheduler، وأي فرع يُضاف هنا يجعل مُشغِّلَين
 * مختلفين لنفس الجدول.
 */
class RemindersDispatch extends Command
{
    protected $signature = 'reminders:dispatch';

    protected $description = 'Run one reminder scheduler cycle (claims, delivery, missed sessions, retention)';

    public function handle(ReminderScheduler $scheduler): int
    {
        $summary = $scheduler->run();

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
