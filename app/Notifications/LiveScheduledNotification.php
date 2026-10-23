<?php
namespace App\Notifications;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use App\Models\LiveSession;
use App\Broadcasting\FcmChannel;

// تُرسل فور تحديد الأدمن ليوم اللايف — متزامنة (بدون queue) لتصل فوراً.
class LiveScheduledNotification extends Notification {
    use Queueable;
    public $session;
    public function __construct(LiveSession $session) { $this->session = $session; }
    public function via(object $notifiable): array {
        return ["database", "broadcast", FcmChannel::class];
    }
    public function toArray(object $notifiable): array {
        $when = $this->session->scheduled_at
            ? $this->session->scheduled_at->format("d/m/Y - H:i")
            : "";
        return [
            "type" => "live_scheduled",
            "message" => "تم تحديد يوم جلسة اللايف: {$when}",
            "session_id" => $this->session->id,
            "scheduled_at" => $this->session->scheduled_at?->toIso8601String(),
        ];
    }
    public function toFcm(object $notifiable): array {
        $when = $this->session->scheduled_at
            ? $this->session->scheduled_at->format("d/m/Y - H:i")
            : "";
        return [
            "title" => "تم تحديد يوم اللايف",
            "body" => "جلسة اللايف يوم {$when} — تجدها في الرئيسية.",
        ];
    }
}
