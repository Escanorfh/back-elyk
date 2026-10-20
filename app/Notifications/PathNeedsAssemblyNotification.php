<?php
namespace App\Notifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Models\PatientPath;
use App\Broadcasting\FcmChannel;

class PathNeedsAssemblyNotification extends Notification implements ShouldQueue {
    use Queueable;
    public $path;
    public function __construct(PatientPath $path) { $this->path = $path; }
    public function via(object $notifiable): array {
        return ["database", "mail", "broadcast", FcmChannel::class];
    }
    public function toMail(object $notifiable): MailMessage {
        $patient = $this->path->patient;
        return (new MailMessage)
            ->subject("New Treatment Path Needs Assembly")
            ->line("{$patient->name} needs a treatment path assembled.")
            ->action("Assemble Path", url("/admin/paths/{$this->path->id}"));
    }
    public function toArray(object $notifiable): array {
        return ["message" => "{$this->path->patient->name} needs a treatment path.", "path_id" => $this->path->id];
    }
    public function toFcm(object $notifiable): array {
        return ["title" => "Path Needs Assembly", "body" => $this->toArray($notifiable)["message"]];
    }
}
