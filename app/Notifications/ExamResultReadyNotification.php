<?php
namespace App\Notifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use App\Models\AttemptResult;
use App\Broadcasting\FcmChannel;

class ExamResultReadyNotification extends Notification implements ShouldQueue {
    use Queueable;
    public $result;
    public function __construct(AttemptResult $result) {
        $this->result = $result;
    }
    public function via(object $notifiable): array {
        return ["database", "mail", "broadcast", FcmChannel::class];
    }
    public function toMail(object $notifiable): MailMessage {
        return (new MailMessage)
            ->subject("Your Check-in Result is Ready")
            ->line("Your recent check-in result is ready: " . ($this->result->severityBand ? $this->result->severityBand->name : 'Recorded'))
            ->line("Here's what this means, and your care team is here to talk it through.")
            ->action("View Result", url("/patient/results/{$this->result->id}"));
    }
    public function toArray(object $notifiable): array {
        $this->result->loadMissing(['disorder', 'severityBand', 'attempt.subscaleResults.severityBand', 'attempt.subscaleResults.subscale']);
        return [
            "message" => "Your recent check-in result is ready.",
            "supportive_message" => "Here's what this means, and your care team is here to talk it through.",
            "result" => new \App\Http\Resources\AttemptResultResource($this->result),
        ];
    }
    public function toFcm(object $notifiable): array {
        return ["title" => "Result Ready", "body" => "Your recent check-in result is ready."];
    }
}
