<?php

namespace App\Notifications;

use App\Broadcasting\FcmChannel;
use App\Models\LiveSession;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

// إعادة الجدولة: الموعد القديم في الرسالة حتى يعرف المريض ما تغيّر.
class LiveRescheduledNotification extends Notification
{
    use Queueable;

    public function __construct(public LiveSession $session) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', FcmChannel::class];
    }

    protected function label(): string
    {
        return $this->session->scheduled_at?->format('d/m/Y - H:i') ?? '';
    }

    protected function previousLabel(): string
    {
        return $this->session->previous_scheduled_at?->format('d/m/Y - H:i') ?? '';
    }

    public function toArray(object $notifiable): array
    {
        $previous = $this->previousLabel();

        return [
            'type' => 'live_rescheduled',
            'message' => $previous !== ''
                ? "تغيّر موعد جلسة اللايف من {$previous} إلى {$this->label()}"
                : "موعد جلسة اللايف الآن: {$this->label()}",
            'session_id' => $this->session->id,
            'scheduled_at' => $this->session->scheduled_at?->toIso8601String(),
            'previous_scheduled_at' => $this->session->previous_scheduled_at?->toIso8601String(),
        ];
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'تغيير موعد اللايف',
            'body' => "الجلسة صارت {$this->label()}",
        ];
    }
}
