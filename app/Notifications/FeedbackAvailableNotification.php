<?php
namespace App\Notifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Models\AssignmentFeedback;
use App\Broadcasting\FcmChannel;

class FeedbackAvailableNotification extends Notification implements ShouldQueue {
    use Queueable;
    public $feedback;
    public function __construct(AssignmentFeedback $feedback) { $this->feedback = $feedback; }
    public function via(object $notifiable): array {
        return ["database", "mail", "broadcast", FcmChannel::class];
    }
    public function toMail(object $notifiable): MailMessage {
        return (new MailMessage)
            ->subject("New Feedback on Your Assignment")
            ->line("Your care team has left feedback on your recent assignment.")
            ->action("View Feedback", url("/patient/assignments/{$this->feedback->assignment_id}"));
    }
    public function toArray(object $notifiable): array {
        return ["message" => "New feedback is available on your assignment.", "feedback_id" => $this->feedback->id];
    }
    public function toFcm(object $notifiable): array {
        return ["title" => "Feedback Available", "body" => "New feedback is available on your assignment."];
    }
}
