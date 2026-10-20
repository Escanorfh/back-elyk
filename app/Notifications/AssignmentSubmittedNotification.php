<?php
namespace App\Notifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Models\Assignment;
use App\Broadcasting\FcmChannel;

class AssignmentSubmittedNotification extends Notification implements ShouldQueue {
    use Queueable;
    public $assignment;
    public function __construct(Assignment $assignment) { $this->assignment = $assignment; }
    public function via(object $notifiable): array {
        return ["database", "mail", "broadcast", FcmChannel::class];
    }
    public function toMail(object $notifiable): MailMessage {
        $patient = $this->assignment->patient;
        return (new MailMessage)
            ->subject("Assignment Submitted")
            ->line("{$patient->name} has submitted an assignment.")
            ->action("Review Assignment", url("/admin/assignments/{$this->assignment->id}"));
    }
    public function toArray(object $notifiable): array {
        return ["message" => "{$this->assignment->patient->name} submitted an assignment.", "assignment_id" => $this->assignment->id];
    }
    public function toFcm(object $notifiable): array {
        return ["title" => "Assignment Submitted", "body" => $this->toArray($notifiable)["message"]];
    }
}
