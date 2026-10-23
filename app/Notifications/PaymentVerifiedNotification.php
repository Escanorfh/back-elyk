<?php

namespace App\Notifications;

use App\Broadcasting\FcmChannel;
use App\Models\LiveSessionPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

// تُرسل للمريض فور تحقق البوابة من الدفع — متزامنة (بدون queue) لتصل فوراً.
class PaymentVerifiedNotification extends Notification
{
    use Queueable;

    public function __construct(public LiveSessionPayment $payment) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', FcmChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'live_payment_verified',
            'message' => $this->message(),
            'session_id' => $this->payment->live_session_id,
            'payment_id' => $this->payment->getKey(),
        ];
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'تم استلام الدفع',
            'body' => $this->message(),
        ];
    }

    protected function message(): string
    {
        $method = match ($this->payment->payment_method) {
            'fawry' => 'فوري',
            'instapay' => 'إنستاباي',
            default => 'إلكترونياً',
        };

        $title = $this->payment->session?->title ?? 'الجلسة';

        return "تم استلام دفعتك بنجاح عبر ({$method}) لجلسة [{$title}]. بانتظار اعتماد الأخصائي.";
    }
}
