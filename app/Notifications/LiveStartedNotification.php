<?php
namespace App\Notifications;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use App\Models\LiveSession;
use App\Broadcasting\FcmChannel;

// تُرسل فور فتح الأدمن للبث — متزامنة (بدون queue) لتصل فوراً.
class LiveStartedNotification extends Notification {
    use Queueable;
    public $session;
    public function __construct(LiveSession $session) { $this->session = $session; }
    public function via(object $notifiable): array {
        return ["database", "broadcast", FcmChannel::class];
    }
    public function toArray(object $notifiable): array {
        return [
            "type" => "live_started",
            "message" => "بدأ البث المباشر الآن — اضغط للانضمام.",
            "session_id" => $this->session->id,
        ];
    }
    public function toFcm(object $notifiable): array {
        return [
            "title" => "البث المباشر بدأ",
            "body" => "الأخصائي بدأ البث الآن — اضغط للانضمام.",
        ];
    }
}
