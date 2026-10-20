<?php
namespace App\Notifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Models\PathStep;
use App\Broadcasting\FcmChannel;

class StepReleasedNotification extends Notification implements ShouldQueue {
    use Queueable;
    public $step;
    public function __construct(PathStep $step) { $this->step = $step; }
    public function via(object $notifiable): array {
        return ["database", "mail", "broadcast", FcmChannel::class];
    }
    public function toMail(object $notifiable): MailMessage {
        return (new MailMessage)
            ->subject("Your Next Session is Ready")
            ->line("Your next treatment session has been released.")
            ->action("View Session", url("/patient/paths/{$this->step->patient_path_id}"));
    }
    public function toArray(object $notifiable): array {
        return ["message" => "Your next session is ready.", "step_id" => $this->step->id];
    }
    public function toFcm(object $notifiable): array {
        return ["title" => "Session Ready", "body" => "Your next session is ready."];
    }
}
