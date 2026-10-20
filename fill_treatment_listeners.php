<?php
$base = __DIR__;

function writeClass($file, $content) {
    global $base;
    $dir = dirname($base . '/' . $file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($base . '/' . $file, $content);
    echo "Updated $file\n";
}

writeClass('app/Listeners/DraftPatientPath.php', '<?php
namespace App\Listeners;
use App\Events\AttemptFinalized;
use App\Models\User;
use App\Models\PatientPath;
use App\Notifications\PathNeedsAssemblyNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

class DraftPatientPath implements ShouldQueue {
    public function handle(AttemptFinalized $event): void {
        $result = $event->result;
        $patient = $result->attempt->patient;
        $disorderId = $result->disorder_id;
        
        $path = PatientPath::where("patient_id", $patient->id)
            ->where("disorder_id", $disorderId)
            ->first();

        if (!$path) {
            $path = PatientPath::create([
                "patient_id" => $patient->id,
                "disorder_id" => $disorderId,
                "status" => "draft"
            ]);
            
            $admins = User::where("role", "admin")->get();
            Notification::send($admins, new PathNeedsAssemblyNotification($path));
        }
    }
}
');

writeClass('app/Notifications/PathNeedsAssemblyNotification.php', '<?php
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
');

writeClass('app/Notifications/StepReleasedNotification.php', '<?php
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
');

writeClass('app/Notifications/AssignmentSubmittedNotification.php', '<?php
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
');

writeClass('app/Notifications/FeedbackAvailableNotification.php', '<?php
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
');

writeClass('app/Policies/PatientPathPolicy.php', '<?php
namespace App\Policies;
use App\Models\User;
use App\Models\PatientPath;
use Illuminate\Auth\Access\HandlesAuthorization;

class PatientPathPolicy {
    use HandlesAuthorization;
    public function view(User $user, PatientPath $path) {
        return $user->role === "admin" || $user->id === $path->patient_id;
    }
}
');

writeClass('app/Policies/AssignmentFeedbackPolicy.php', '<?php
namespace App\Policies;
use App\Models\User;
use App\Models\AssignmentFeedback;
use Illuminate\Auth\Access\HandlesAuthorization;

class AssignmentFeedbackPolicy {
    use HandlesAuthorization;
    public function view(User $user, AssignmentFeedback $feedback) {
        if ($user->role === "admin") return true;
        return $feedback->is_visible_to_patient && $user->id === $feedback->assignment->patient_id;
    }
}
');

echo "Listeners, Notifications, and Policies populated.\n";
