<?php
namespace App\Notifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Notifications\Messages\BroadcastMessage;
use App\Models\AttemptResult;
use App\Broadcasting\FcmChannel;
use App\Models\ChatThread;

class SevereExamResultNotification extends Notification implements ShouldQueue {
    use Queueable;
    public $result;
    public function __construct(AttemptResult $result) {
        $this->result = $result;
    }
    public function via(object $notifiable): array {
        return ["database", "mail", "broadcast", FcmChannel::class];
    }
    public function toMail(object $notifiable): MailMessage {
        $patient = $this->result->attempt->patient;
        return (new MailMessage)
            ->subject("Severe Result Alert")
            ->line("A severe exam result was just submitted by {$patient->name}.")
            ->action("View Profile / Chat", url("/admin/chat/{$patient->id}"));
    }
    public function toArray(object $notifiable): array {
        $patient = $this->result->attempt->patient;
        $thread = ChatThread::firstOrCreate(["patient_id" => $patient->id]);
        return [
            "message" => "Severe result alert for {$patient->name}",
            "patient_id" => $patient->id,
            "patient_name" => $patient->name,
            "thread_id" => $thread->id,
            "result_id" => $this->result->id,
        ];
    }
    public function toFcm(object $notifiable): array {
        return ["title" => "Severe Result Alert", "body" => $this->toArray($notifiable)["message"]];
    }
}
