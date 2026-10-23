<?php

namespace App\Notifications;

use App\Broadcasting\FcmChannel;
use App\Models\LiveSession;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

// تُرسل فور موافقة الأدمن على انضمام المريض للجلسة — متزامنة (بدون queue) لتصل فوراً.
class SessionApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(public LiveSession $session) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', FcmChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'live_session_approved',
            'message' => 'تهانينا! تم اعتماد موعد جلستك مع الأخصائي. أصبحت الجلسة جاهزة للانضمام.',
            'session_id' => $this->session->id,
        ];
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'تم اعتماد الجلسة',
            'body' => 'تهانينا! تم اعتماد موعد جلستك مع الأخصائي. أصبحت الجلسة جاهزة للانضمام.',
        ];
    }
}
