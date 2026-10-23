<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\AttemptResult;

class AdminExamResultNotification extends Notification
{
    use Queueable;
    public $result;

    /**
     * Create a new notification instance.
     */
    public function __construct(AttemptResult $result)
    {
        $this->result = $result;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => 'New exam result recorded for patient ' . $this->result->attempt->patient->name,
            'result_id' => $this->result->id,
            'exam_name' => $this->result->attempt->exam->name,
        ];
    }
}
