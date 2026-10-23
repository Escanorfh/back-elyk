<?php
namespace App\Broadcasting;
use Illuminate\Notifications\Notification;
use App\Services\FcmService;
class FcmChannel {
    protected $fcmService;
    public function __construct(FcmService $fcmService) {
        $this->fcmService = $fcmService;
    }
    public function send($notifiable, Notification $notification) {
        if (!method_exists($notification, "toFcm")) {
            return;
        }
        $payload = $notification->toFcm($notifiable);
        $this->fcmService->sendToUser($notifiable, $payload);
    }
}
