<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\AttemptResult;
use App\Broadcasting\FcmChannel;
use App\Models\ChatThread;

/**
 * إشعار أحمر فوري للأدمن: المريض X عدّى الـ max level في فورم.
 * يُرسل عبر database + broadcast + FCM + mail — ويُشغَّل synchronously
 * (بدون queue) عشان يوصل فور التسليم.
 */
class MaxLevelBreachNotification extends Notification
{
    use Queueable;

    public $result;
    public $maxLevel;

    public function __construct(AttemptResult $result, int $maxLevel)
    {
        $this->result = $result;
        $this->maxLevel = $maxLevel;
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail', 'broadcast', FcmChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $patient = $this->result->attempt->patient;
        $examName = $this->result->attempt->exam->name_ar
            ?? $this->result->attempt->exam->name;
        return (new MailMessage)
            ->subject("تنبيه أحمر: تجاوز الحد الأقصى")
            ->line("المريض {$patient->name} سجّل {$this->result->total_score} نقطة في فورم {$examName} (الحد الأقصى {$this->maxLevel}).")
            ->action("View Profile / Chat", url("/admin/chat/{$patient->id}"));
    }

    public function toArray(object $notifiable): array
    {
        $patient = $this->result->attempt->patient;
        $exam = $this->result->attempt->exam;
        $examName = $exam->name_ar ?? $exam->name;
        $thread = ChatThread::firstOrCreate(['patient_id' => $patient->id]);
        return [
            'message' => "المريض {$patient->name} عدّى الحد الأقصى ({$this->maxLevel}) في فورم {$examName} بدرجة {$this->result->total_score}",
            'patient_id' => $patient->id,
            'patient_name' => $patient->name,
            'thread_id' => $thread->id,
            'result_id' => $this->result->id,
            'exam_id' => $exam->id,
            'total_score' => $this->result->total_score,
            'max_level_score' => $this->maxLevel,
            'severity' => 'critical',
        ];
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'تنبيه أحمر: تجاوز الحد الأقصى',
            'body' => $this->toArray($notifiable)['message'],
        ];
    }
}
