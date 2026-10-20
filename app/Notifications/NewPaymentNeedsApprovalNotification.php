<?php

namespace App\Notifications;

use App\Broadcasting\FcmChannel;
use App\Models\LiveSessionPayment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Throwable;

// تُرسل للإدارة فور تحقق دفعة جديدة — متزامنة (بدون queue) لتصل فوراً.
class NewPaymentNeedsApprovalNotification extends Notification
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
            'type' => 'live_payment_needs_approval',
            'message' => $this->message(),
            'session_id' => $this->payment->live_session_id,
            'payment_id' => $this->payment->getKey(),
            'patient_id' => $this->payment->patient_id,
        ];
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'دفعة جديدة بانتظار الاعتماد',
            'body' => $this->message(),
        ];
    }

    protected function message(): string
    {
        $amount = (int) $this->payment->amount;
        $currency = $this->payment->currency === 'EGP' ? 'ج.م' : (string) $this->payment->currency;
        $name = $this->payment->patient?->name ?? 'مريض';

        return "دفعة جديدة مؤكدة بمبلغ {$amount} {$currency} من المريض [{$name}]. يرجى اعتماد الجلسة.";
    }

    /**
     * Notify the session owner admin (falling back to all admins).
     * Never throws — notifications must not break webhook acknowledgement.
     */
    public static function notifyAdmins(LiveSessionPayment $payment): void
    {
        try {
            $owner = $payment->session?->admin;

            if ($owner) {
                $owner->notify(new self($payment));

                return;
            }

            User::where('role', 'admin')->each(
                fn (User $admin) => $admin->notify(new self($payment))
            );
        } catch (Throwable $e) {
            \Log::warning('Admin payment notification failed: '.$e->getMessage(), [
                'payment_id' => $payment->getKey(),
            ]);
        }
    }
}
