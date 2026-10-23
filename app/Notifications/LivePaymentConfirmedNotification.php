<?php
namespace App\Notifications;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use App\Models\LiveSession;
use App\Broadcasting\FcmChannel;

// تُرسل فور تأكيد الأدمن لدفع المريض — متزامنة (بدون queue) لتصل فوراً.
class LivePaymentConfirmedNotification extends Notification {
    use Queueable;
    public $session;
    public function __construct(LiveSession $session) { $this->session = $session; }
    public function via(object $notifiable): array {
        return ["database", "broadcast", FcmChannel::class];
    }
    public function toArray(object $notifiable): array {
        return [
            "type" => "live_payment_confirmed",
            "message" => "تم تأكيد دفع رسوم اللايف — يمكنك الانضمام عند بدء البث.",
            "session_id" => $this->session->id,
        ];
    }
    public function toFcm(object $notifiable): array {
        return [
            "title" => "تم تأكيد الدفع",
            "body" => "تم تأكيد دفع رسوم اللايف — يمكنك الانضمام عند بدء البث.",
        ];
    }
}
