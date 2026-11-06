<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Support\Reminders\ReminderSettings;
use Illuminate\Console\Command;

/**
 * تجهيز مفتاح المُشغِّل بلا migration وبلا .env: الصفة تُقرأ من الجدول المزروع في 4.1، والمفتاح
 * نفسه لا يُخزَّن أبدًا — بصمته sha256 فقط. من يسرق قاعدة البيانات لا يرسل تذكيرات.
 *
 * بلا --rotate تُقرأ الحالة فقط، فلا يدور مفتاح إنتاجي بكتابة عجلة على الطرفية.
 * النص الصريح يُطبع مرة واحدة ثم يضيع: هذا هو العقد نفسه الذي تستخدمه tokens الخدمات، وهو السبب
 * أن وضعه في cron-job.org يكون في custom variable لا في الرابط المسجَّل عندهم.
 */
class RemindersSchedulerToken extends Command
{
    protected $signature = 'reminders:scheduler-token {--rotate : Generate a new scheduler secret and store its fingerprint}';

    protected $description = 'Show or rotate the scheduler endpoint secret (app_settings.reminders.scheduler_token)';

    public function handle(): int
    {
        if (! $this->option('rotate')) {
            $hash = ReminderSettings::load()->schedulerTokenHash();

            if ($hash === null) {
                $this->warn('No scheduler secret is configured — the endpoint answers 503 until one exists.');

                return self::SUCCESS;
            }

            $this->line("Configured fingerprint: {$hash}");
            $this->line('The secret itself is not stored and cannot be recovered; use --rotate to replace it.');

            return self::SUCCESS;
        }

        $secret = bin2hex(random_bytes(32));

        AppSetting::setValue(
            ReminderSettings::TOKEN_KEY,
            ReminderSettings::fingerprint($secret),
            'string',
            ReminderSettings::GROUP
        );

        $this->info('New scheduler secret (shown once — it is not recoverable):');
        $this->line($secret);
        $this->line('Send it as the X-Scheduler-Token header. Keep it in the cron service\'s secret/variable store, never in the URL.');

        return self::SUCCESS;
    }
}
