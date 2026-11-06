<?php

namespace App\Notifications;

use App\Broadcasting\FcmChannel;
use App\Models\LiveSession;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

// إلغاء الأدمن للجلسة — الإشعار لا يفشل الإلغاء (الخدمة تلتهم الاستثناءات).
class LiveCancelledNotification extends Notification
{
    use Queueable;

    public function __construct(public LiveSession $session, public ?string $reason = null) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', FcmChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        $when = $this->session->scheduled_at?->format('d/m/Y - H:i') ?? '';

        return [
            'type' => 'live_cancelled',
            'message' => "أُلغيت جلسة اللايف: {$when}".($this->reason ? " — {$this->reason}" : ''),
            'session_id' => $this->session->id,
            'scheduled_at' => $this->session->scheduled_at?->toIso8601String(),
        ];
    }

    public function toFcm(object $notifiable): array
    {
        $when = $this->session->scheduled_at?->format('d/m/Y - H:i') ?? '';

        return [
            'title' => 'إلغاء جلسة اللايف',
            'body' => "جلسة {$when} أُلغيت".($this->reason ? " — {$this->reason}" : '.'),
        ];
    }
}
